<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Catalog\Application\Search\SearchLog;
use Modules\Ops\Application\Command\MakeDailyReport\MakeDailyReport;
use Modules\Ops\Application\Command\MakeDailyReport\MakeDailyReportHandler;
use Modules\Ops\Application\Command\PruneDailyReports\PruneDailyReports;
use Modules\Ops\Application\Command\PruneDailyReports\PruneDailyReportsHandler;
use Modules\Ops\Application\DailyReport\DailyReport;
use Modules\Ops\Application\DailyReport\DailyReportMaker;
use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Application\DailyReport\DailyReportReader;
use Modules\Ops\Application\DailyReport\DailyReportRenderer;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\DownloadDailyReport\DownloadDailyReport;
use Modules\Ops\Application\Query\DownloadDailyReport\DownloadDailyReportHandler;
use Modules\Ops\Application\Query\ListDailyReports\ListDailyReports;
use Modules\Ops\Application\Query\ListDailyReports\ListDailyReportsHandler;
use Modules\Ops\Domain\Exception\DailyReportNotFound;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\DailyReportFile;
use Modules\Ops\Infrastructure\Files\LocalReportFiles;
use Modules\Ops\Infrastructure\Pdf\ReportFormat;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Application\Command\CancelOrder\CancelOrder;
use Modules\Sales\Application\Command\CancelOrder\CancelOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\DailyReportFixtures as Dx;
use Tests\Modules\Ops\Support\PdfText;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The daily report (ops.md §1.11, §8): its figures against the orders counted by hand - a cancelled or
| rejected order never counts, a refund comes off on the day its return was asked; every store's report
| with one total per currency; who may make, list and open one; a file kept exactly as it was made; the
| twelve months it is kept.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake(LocalReportFiles::DISK);
});

function dailyStore(string $code): StoreDto
{
    $store = app(PlatformApi::class)->store(StoreId::fromString(Fx::storeId($code)));

    return $store ?? throw new LogicException('No such store.');
}

function dailyMake(?string $store, ?string $day = null): DailyReportFile
{
    return app(MakeDailyReportHandler::class)->handle(new MakeDailyReport($store === null ? null : Fx::storeId($store), $day));
}

it('counts the day as the orders counted by hand: the cancelled and rejected left out, the refund on the day its return was asked', function () {
    $day = Dx::aDay();
    $row = DB::table('sales.orders')->where('id', $day['delivered'])->first();
    $paid = (int) DB::table('sales.orders')->where('id', $day['delivered'])->value('paid_minor');

    $report = app(DailyReportReader::class)->store(dailyStore('sa'), CarbonImmutable::now()->addSecond()->toDateTimeImmutable(), null);

    expect([$report->counts->placed, $report->counts->counted, $report->counts->cancelled, $report->counts->rejected, $report->counts->individuals])->toBe([3, 1, 1, 1, 3])
        // Revenue net of the refund of the return asked today and completed (ops.md §1.10).
        ->and([$report->today->orders, $report->today->revenue->minorUnits])->toBe([1, (int) $row?->goods_total_minor - 100_00])
        ->and([$report->vat->minorUnits, $report->shipping->minorUnits])->toBe([(int) $row?->vat_minor, (int) $row?->shipping_fee_minor])
        // One piece of the 100.00 size refunded; one of the 50.00 asked, its refund estimated.
        ->and([$report->refunds->minorUnits, $report->waitingRefundsTotal->minorUnits])->toBe([100_00, 50_00])
        ->and(array_map(static fn ($one): array => [$one->returnId, $one->orderNumber], $report->waitingRefunds))->toBe([[$day['open'], $day['number']]])
        ->and(array_map(static fn ($way): array => [$way->way, $way->records, $way->amount->minorUnits], $report->money->payments))->toBe([['BANK_TRANSFER', 1, $paid]])
        ->and(array_map(static fn ($size): array => [$size->variantId, $size->units, $size->revenue->minorUnits], $report->topSizes))->toBe([[$day['variants'][0], 2, 200_00], [$day['variants'][1], 1, 50_00]])
        ->and(array_sum(array_map(static fn ($category): int => $category->revenue->minorUnits, $report->categories)))->toBe(250_00)
        // Yesterday had nothing; the month so far is today.
        ->and([$report->yesterday->orders, $report->monthToDate->orders, $report->monthToDate->revenue->minorUnits])->toBe([0, 1, (int) $row?->goods_total_minor - 100_00])
        ->and($report->from->format('H:i'))->toBe('00:00')
        ->and($report->from->getTimezone()->getName())->toBe('Asia/Riyadh');
});

it('makes every store\'s report with each store in its own day, then one total per currency, never converted', function () {
    $day = Dx::aDay();
    $row = DB::table('sales.orders')->where('id', $day['delivered'])->first();
    $stores = app(DailyReportPeople::class)->everyStore();

    $report = app(DailyReportReader::class)->report($stores, CarbonImmutable::now()->addSecond()->toDateTimeImmutable(), null, 'Asia/Riyadh');
    $totals = [];
    foreach ($report->totals as $total) {
        $totals[$total->currencyCode] = [$total->today->orders, $total->today->revenue->minorUnits, count($total->storeIds)];
    }

    expect($report->allStores)->toBeTrue()
        ->and(array_map(static fn ($one): string => $one->from->getTimezone()->getName(), $report->stores))
        ->toBe(array_map(static fn (StoreDto $store): string => $store->timezone, $stores))
        ->and($totals)->toBe(['SAR' => [1, (int) $row?->goods_total_minor - 100_00, 1], 'EGP' => [0, 0, 1], 'AED' => [0, 0, 1]]);
});

it('keeps the file exactly as it was made: a later change to the day leaves it as it was', function () {
    // One order placed yesterday, the report of that past day, then the order cancelled.
    $ready = Cx::ready();
    $order = Cx::place(Cx::start($ready['address'])->id)->id;
    DB::table('sales.orders')->update(['placed_at' => CarbonImmutable::now()->subDay()]);
    $yesterday = CarbonImmutable::now('Asia/Riyadh')->subDay()->format('Y-m-d');
    $admin = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($admin);

    $first = dailyMake('sa', $yesterday);
    $kept = Storage::disk(LocalReportFiles::DISK)->get($first->file);
    $before = app(DailyReportReader::class)->store(dailyStore('sa'), CarbonImmutable::now()->toDateTimeImmutable(), $yesterday)->today->orders;

    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_CANCEL], ['sa']));
    app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Changed after the report'));
    Fx::actAsStaff($admin);
    $later = dailyMake('sa', $yesterday);
    $download = app(DownloadDailyReportHandler::class)->handle(new DownloadDailyReport($first->id));

    expect($kept)->toStartWith('%PDF')
        ->and($download->pdf)->toBe($kept)
        ->and($download->filename)->toBe("daily-report-sa-{$yesterday}.pdf")
        ->and([$first->day, $first->pastDay, $first->locale, $first->madeBy])->toBe([$yesterday, true, 'en', $admin])
        ->and($later->file)->not->toBe($first->file)
        ->and([$before, app(DailyReportReader::class)->store(dailyStore('sa'), CarbonImmutable::now()->toDateTimeImmutable(), $yesterday)->today->orders])->toBe([1, 0]);
});

it('lets a person make a store\'s report where they hold ops.report.daily, and every store\'s only a super admin', function () {
    Dx::fakeRenderer();
    $staff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($staff);

    $sa = dailyMake('sa');

    expect([$sa->store?->value, $sa->pastDay, $sa->bytes > 0, Storage::disk(LocalReportFiles::DISK)->exists($sa->file)])->toBe([Fx::storeId('sa'), false, true, true])
        ->and(fn () => dailyMake('ae'))->toThrow(Unauthorized::class)
        ->and(fn () => dailyMake(null))->toThrow(Unauthorized::class);

    // An admin of every store is still not a super admin.
    Fx::actAsAdmin(['sa', 'eg', 'ae'], [OpsPermissions::REPORT_DAILY]);
    expect(fn () => dailyMake(null))->toThrow(Unauthorized::class);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $all = dailyMake(null);
    expect([$all->store, $all->day])->toBe([null, CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d')]);

    Fx::actAsStaff(Fx::staffWith(['ops.report.view'], ['sa']));
    expect(fn () => dailyMake('sa'))->toThrow(Unauthorized::class);
});

it('refuses a store that does not exist, and a past day that is not one', function (string $day) {
    Dx::fakeRenderer();
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(fn () => dailyMake('sa', $day))->toThrow(InvalidOpsInput::class);
})->with([
    'today' => [CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d')],
    'tomorrow' => [CarbonImmutable::now('Asia/Riyadh')->addDay()->format('Y-m-d')],
    'not a date' => ['2026-02-30'],
    'another form' => ['30/10/2026'],
]);

it('refuses an unknown store as an input, not a permission', function () {
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(fn () => app(MakeDailyReportHandler::class)->handle(new MakeDailyReport('01jbxqz2y8k4n6p9r3t5v7w1xa')))->toThrow(InvalidOpsInput::class)
        ->and(fn () => app(MakeDailyReportHandler::class)->handle(new MakeDailyReport('nope')))->toThrow(InvalidOpsInput::class);
});

it('lists the reports of the reader\'s stores whoever made them, every store\'s only to super admins, and opens no other', function () {
    Dx::fakeRenderer();
    $superAdmin = Fx::staff(superAdmin: true);
    Fx::actAsStaff($superAdmin);
    $all = dailyMake(null);
    $ae = dailyMake('ae');
    $saStaff = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($saStaff);
    $sa = dailyMake('sa');
    $ids = static fn (): array => array_map(static fn (DailyReportFile $row): string => $row->id, app(ListDailyReportsHandler::class)->handle(new ListDailyReports)->rows);

    expect($ids())->toBe([$sa->id])
        ->and(fn () => app(DownloadDailyReportHandler::class)->handle(new DownloadDailyReport($ae->id)))->toThrow(DailyReportNotFound::class)
        ->and(fn () => app(DownloadDailyReportHandler::class)->handle(new DownloadDailyReport($all->id)))->toThrow(DailyReportNotFound::class)
        ->and(fn () => app(DownloadDailyReportHandler::class)->handle(new DownloadDailyReport('nope')))->toThrow(DailyReportNotFound::class);

    Fx::actAsStaff($superAdmin);
    expect($ids())->toBe([$sa->id, $ae->id, $all->id]);

    Fx::actAsStaff(Fx::staffWith(['ops.report.view'], ['sa']));
    expect(fn () => app(ListDailyReportsHandler::class)->handle(new ListDailyReports))->toThrow(Unauthorized::class);
});

it('removes the reports twelve months old with their files, keeps the rest, and is the system\'s alone', function () {
    Dx::fakeRenderer();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $old = dailyMake('sa');
    $kept = dailyMake('sa');
    DB::table('ops.daily_reports')->where('id', $old->id)->update(['made_at' => CarbonImmutable::now()->subMonthsNoOverflow(12)->subMinute()]);
    DB::table('ops.daily_reports')->where('id', $kept->id)->update(['made_at' => CarbonImmutable::now()->subMonthsNoOverflow(12)->addMinute()]);

    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']));
    expect(fn () => app(PruneDailyReportsHandler::class)->handle(new PruneDailyReports))->toThrow(Unauthorized::class);

    $gone = Fx::asSystem(fn (): int => app(PruneDailyReportsHandler::class)->handle(new PruneDailyReports));

    expect($gone)->toBe(1)
        ->and(DB::table('ops.daily_reports')->pluck('id')->all())->toBe([$kept->id])
        ->and(Storage::disk(LocalReportFiles::DISK)->exists($old->file))->toBeFalse()
        ->and(Storage::disk(LocalReportFiles::DISK)->exists($kept->file))->toBeTrue();
});

it('refuses in the code first what the daily report tables refuse', function () {
    $made = new DateTimeImmutable;

    expect(fn () => new DailyReportFile('01jbxqz2y8k4n6p9r3t5v7w1xa', null, '2026-10-30', false, 'x', $made, 'fr', DailyReportFile::fileFor('01jbxqz2y8k4n6p9r3t5v7w1xa', $made), 10))->toThrow(InvalidOpsInput::class)
        ->and(fn () => new DailyReportFile('01jbxqz2y8k4n6p9r3t5v7w1xa', null, '2026-10-30', false, 'x', $made, 'ar', '../secret.pdf', 10))->toThrow(InvalidOpsInput::class)
        ->and(fn () => new DailyReportFile('01jbxqz2y8k4n6p9r3t5v7w1xa', null, '2026-10-30', false, 'x', $made, 'ar', DailyReportFile::fileFor('01jbxqz2y8k4n6p9r3t5v7w1xa', $made), 0))->toThrow(InvalidOpsInput::class);
});

it('puts the switched-off stores in every store\'s report, marked, their figures in the totals (owner, 2026-10-30)', function () {
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $stores = app(DailyReportPeople::class)->everyStore();

    $report = app(DailyReportReader::class)->report($stores, CarbonImmutable::now()->toDateTimeImmutable(), null, 'Asia/Riyadh');
    $html = view('ops::daily-report', ['report' => $report, 'locale' => 'en', 'rtl' => false, 'f' => new ReportFormat('en'), 'logo' => public_path('icons/email-logo.png')])->render();

    expect(array_map(static fn ($one): array => [$one->store->code, $one->store->isActive], $report->stores))->toBe([['sa', true], ['eg', false], ['ae', true]])
        ->and(array_map(static fn ($total): string => $total->currencyCode, $report->totals))->toBe(['SAR', 'EGP', 'AED'])
        ->and(substr_count($html, 'Switched off'))->toBe(1)
        ->and(dailyMake(null)->store)->toBeNull();
});

it('takes the refunds off the revenue: 250 sold, 100 refunded, 50 waiting is 150, and 100 if the waiting go through', function () {
    Dx::aDay();

    $report = app(DailyReportReader::class)->store(dailyStore('sa'), CarbonImmutable::now()->addSecond()->toDateTimeImmutable(), null);

    expect([$report->today->revenue->minorUnits, $report->refunds->minorUnits, $report->waitingRefundsTotal->minorUnits])->toBe([150_00, 100_00, 50_00])
        ->and($report->today->revenue->subtract($report->waitingRefundsTotal)->minorUnits)->toBe(100_00)
        ->and($report->monthToDate->revenue->minorUnits)->toBe(150_00);
});

it('compares with yesterday\'s same hours, and starts the month on the 1st at the store\'s midnight', function () {
    $orders = [];
    foreach (range(1, 3) as $ignored) {
        $ready = Cx::ready();
        $orders[] = Cx::place(Cx::start($ready['address'])->id)->id;
    }
    // A past day, the 1st of a month: one order that day, one the evening before (the last day of the
    // month before), one two days before - each in Riyadh time.
    $at = static fn (string $local): string => CarbonImmutable::parse($local, 'Asia/Riyadh')->utc()->format('Y-m-d H:i:sP');
    DB::table('sales.orders')->where('id', $orders[0])->update(['placed_at' => $at('2026-10-01 10:00')]);
    DB::table('sales.orders')->where('id', $orders[1])->update(['placed_at' => $at('2026-09-30 22:00')]);
    DB::table('sales.orders')->where('id', $orders[2])->update(['placed_at' => $at('2026-09-29 23:30')]);
    $pastDay = app(DailyReportReader::class)->store(dailyStore('sa'), CarbonImmutable::now()->toDateTimeImmutable(), '2026-10-01');

    // Today until 12:00: yesterday's same hours hold the 11:00 order, not the 13:00 one.
    DB::table('sales.orders')->where('id', $orders[1])->update(['placed_at' => $at('2026-10-14 11:00')]);
    DB::table('sales.orders')->where('id', $orders[2])->update(['placed_at' => $at('2026-10-14 13:00')]);
    DB::table('sales.orders')->where('id', $orders[0])->update(['placed_at' => $at('2026-10-15 09:00')]);
    $sameHours = app(DailyReportReader::class)->store(dailyStore('sa'), CarbonImmutable::parse('2026-10-15 12:00', 'Asia/Riyadh')->toDateTimeImmutable(), null);

    expect([$pastDay->today->orders, $pastDay->yesterday->orders, $pastDay->monthToDate->orders])->toBe([1, 1, 1])
        ->and([$sameHours->today->orders, $sameHours->yesterday->orders, $sameHours->monthToDate->orders])->toBe([1, 1, 3]);
});

it('reads a store east of the base store in its own day', function () {
    $ready = Cx::ready(storeCode: 'ae');
    $order = Cx::place(Cx::start($ready['address'], storeCode: 'ae')->id, 'ae')->id;
    // 00:30 in Dubai is 23:30 the evening before in Riyadh.
    $dubai = CarbonImmutable::parse('2026-10-15 00:30', 'Asia/Dubai');
    DB::table('sales.orders')->where('id', $order)->update(['placed_at' => $dubai->utc()->format('Y-m-d H:i:sP')]);

    $report = app(DailyReportReader::class)->store(dailyStore('ae'), $dubai->setTime(12, 0)->toDateTimeImmutable(), null);

    expect([$report->from->format('Y-m-d H:i'), $report->from->getTimezone()->getName(), $report->today->orders, $report->yesterday->orders])
        ->toBe(['2026-10-15 00:00', 'Asia/Dubai', 1, 0]);
});

it('refuses every store\'s past day while it is still today in one of them, west of the base store', function () {
    Dx::fakeRenderer();
    $person = app(AccessApi::class)->staff(Fx::staff(superAdmin: true)) ?? throw new LogicException('No staff.');
    // 21:30 UTC: 00:30 on the 16th in Riyadh, 23:30 on the 15th in Cairo.
    $now = new DateTimeImmutable('2026-01-15 21:30:00 UTC');
    $make = static fn (string $day) => app(DailyReportMaker::class)->make($person, null, app(DailyReportPeople::class)->everyStore(), $day, 'Asia/Riyadh', $now);

    expect(fn () => $make('2026-01-15'))->toThrow(InvalidOpsInput::class)
        ->and($make('2026-01-14')->day)->toBe('2026-01-14');
});

it('writes people\'s text escaped, and prints "{nbpg}", "{nb}" and "{PAGENO}" in the PDF as typed, never a page number or a box', function () {
    Dx::aDay();
    DB::table('catalog.products')->update(['name_en' => '<img src=x onerror=alert(1)> {PAGENO}']);
    app(SearchLog::class)->record(Fx::storeId('sa'), 'en', '{nbpg} {nb} <b>door</b>', 0);

    $report = app(DailyReportReader::class)->store(dailyStore('sa'), CarbonImmutable::now()->addSecond()->toDateTimeImmutable(), null);
    $daily = new DailyReport(CarbonImmutable::now()->toDateTimeImmutable(), 'Asia/Riyadh', false, [$report], []);
    $html = view('ops::daily-report', ['report' => $daily, 'locale' => 'en', 'rtl' => false, 'f' => new ReportFormat('en'), 'logo' => public_path('icons/email-logo.png')])->render();
    // The real PDF, read back: mPDF writes its page numbers over the placeholders after the HTML.
    $text = PdfText::of(app(DailyReportRenderer::class)->render($daily, 'en'));

    expect($html)->not->toContain('<img src=x')
        ->and($text)->toContain('<img src=x onerror=alert(1)> {PAGENO}')
        ->and($text)->toContain('{nbpg} {nb} <b>door</b>')
        // Exactly the characters typed, nothing between them: no joiner or other code the font lacks
        // (IBM Plex Sans Arabic draws every printable ASCII character, both weights; checked 2026-10-30).
        ->and($text)->not->toContain("\u{2060}");
});

it('opens a report someone else made to anyone with the permission in its store, and to no one else', function () {
    Dx::fakeRenderer();
    $maker = Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']);
    Fx::actAsStaff($maker);
    $made = dailyMake('sa');

    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']));
    expect(app(DownloadDailyReportHandler::class)->handle(new DownloadDailyReport($made->id))->pdf)->toStartWith('%PDF');

    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['ae']));
    expect(fn () => app(DownloadDailyReportHandler::class)->handle(new DownloadDailyReport($made->id)))->toThrow(DailyReportNotFound::class);
});

it('sweeps the month folders older than twelve months, files whose row is gone included, and nothing newer', function () {
    $disk = Storage::disk(LocalReportFiles::DISK);
    $old = CarbonImmutable::now('UTC')->subMonthsNoOverflow(13);
    $disk->put('ops/daily-reports/'.$old->format('Y/m').'/01jbxqz2y8k4n6p9r3t5v7w1xa.pdf', '%PDF-1.4 left behind');
    $disk->put('ops/daily-reports/'.CarbonImmutable::now('UTC')->format('Y/m').'/01jbxqz2y8k4n6p9r3t5v7w1xb.pdf', '%PDF-1.4 kept');
    $disk->put('ops/other/keep.txt', 'not the reports\'');

    Fx::asSystem(fn (): int => app(PruneDailyReportsHandler::class)->handle(new PruneDailyReports));

    expect($disk->exists('ops/daily-reports/'.$old->format('Y/m').'/01jbxqz2y8k4n6p9r3t5v7w1xa.pdf'))->toBeFalse()
        ->and($disk->exists('ops/daily-reports/'.CarbonImmutable::now('UTC')->format('Y/m').'/01jbxqz2y8k4n6p9r3t5v7w1xb.pdf'))->toBeTrue()
        ->and($disk->exists('ops/other/keep.txt'))->toBeTrue();
});
