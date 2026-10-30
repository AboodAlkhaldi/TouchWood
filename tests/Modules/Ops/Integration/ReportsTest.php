<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Search\SearchLog;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Feedback\Application\Command\SaveToWishlist\SaveToWishlist;
use Modules\Feedback\Application\Command\SaveToWishlist\SaveToWishlistHandler;
use Modules\Feedback\Public\Contracts\FeedbackApi;
use Modules\Feedback\Public\Dto\FavouriteCountDto;
use Modules\Feedback\Public\Dto\FavouriteCountsDto;
use Modules\Feedback\Public\Dto\FeedbackCountsDto;
use Modules\Feedback\Public\Dto\QuestionForMessageDto;
use Modules\Feedback\Public\Dto\ReviewForMessageDto;
use Modules\Inventory\Public\InventoryPermissions;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\DownloadReport\DownloadReport;
use Modules\Ops\Application\Query\DownloadReport\DownloadReportHandler;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\ReportFixtures as Rx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The reports (ops.md §1.10, §3, §7, §8): revenue against the orders counted by hand - a cancelled or
| rejected order never counts, a refund comes off on the day its return was asked, the waiting ones in a
| second figure, VAT waiting for Sales; every store's report with one total per currency and the switched-off
| stores marked; product sales, stock, searches, favourites; who may read which; the days refused.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function reportsToday(): string
{
    return CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d');
}

function reportsReader(string ...$stores): string
{
    $staff = Fx::staffWith([OpsPermissions::REPORT_VIEW], $stores === [] ? ['sa'] : array_values($stores));
    Fx::actAsStaff($staff);

    return $staff;
}

it('counts revenue as the orders counted by hand: the cancelled and rejected left out, the refund on the day its return was asked', function () {
    $day = Rx::aDay();
    $order = DB::table('sales.orders')->where('id', $day['delivered'])->first();
    $goods = (int) $order?->goods_total_minor;
    $money = static fn (int $minor): string => number_format($minor / 100, 2, '.', '');
    $today = reportsToday();
    reportsReader();

    $preview = Rx::preview('revenue', Fx::storeId('sa'), $today, $today);
    $waits = 'Waits for Sales (VAT of refunds)';

    expect(array_map(static fn ($sheet): string => $sheet->name, $preview->report->sheets))->toBe(['Revenue', 'Waiting refunds', 'By category'])
        ->and([$preview->report->title, $preview->report->subtitle, $preview->report->locale, $preview->report->filename])
        ->toBe(['Revenue', "Saudi Arabia · {$today} to {$today}", 'en', "revenue-sa-{$today}-{$today}.xlsx"])
        // One order counts: 250.00 sold, 100.00 refunded, 50.00 waiting - 150.00, and 100.00 if the waiting go through.
        ->and(Rx::rows($preview))->toBe([
            ['Saudi Arabia', $today, 'SAR', 1, $money($goods - 100_00), $money($goods - 150_00), $waits, $money((int) $order?->shipping_fee_minor), '100.00', '50.00'],
            ['Total', null, 'SAR', 1, $money($goods - 100_00), $money($goods - 150_00), $waits, $money((int) $order?->shipping_fee_minor), '100.00', '50.00'],
        ])
        ->and($goods)->toBe(250_00)
        // The return still waiting, by return and order number - no customer.
        ->and(Rx::rows($preview, 1))->toBe([['Saudi Arabia', $today, $day['open'], $day['number'], 'SAR', '50.00']])
        // By category: the goods sold, returns left in.
        ->and(array_map(static fn (array $row): array => array_slice($row, 2), Rx::rows($preview, 2)))->toBe([['SAR', 3, '250.00']]);
});

it('sums the days of a month, and leaves the waiting figure empty when nothing waits', function () {
    $ready = Cx::ready();
    $order = Cx::place(Cx::start($ready['address'])->id)->id;
    $at = static fn (string $local): string => CarbonImmutable::parse($local, 'Asia/Riyadh')->utc()->format('Y-m-d H:i:sP');
    DB::table('sales.orders')->where('id', $order)->update(['placed_at' => $at('2026-09-03 10:00')]);
    $second = Cx::ready();
    $other = Cx::place(Cx::start($second['address'])->id)->id;
    DB::table('sales.orders')->where('id', $other)->update(['placed_at' => $at('2026-09-20 23:30')]);
    reportsReader();

    $byDay = Rx::rows(Rx::preview('revenue', Fx::storeId('sa'), '2026-09-01', '2026-09-30'));
    $byMonth = Rx::rows(Rx::preview('revenue', Fx::storeId('sa'), '2026-09-01', '2026-09-30', 'month'));

    expect(array_map(static fn (array $row): array => [$row[1], $row[3], $row[4], $row[5]], $byDay))->toBe([['2026-09-03', 1, '250.00', null], ['2026-09-20', 1, '250.00', null], [null, 2, '500.00', null]])
        ->and(array_map(static fn (array $row): array => [$row[1], $row[3], $row[4]], $byMonth))->toBe([['2026-09', 2, '500.00'], [null, 2, '500.00']]);
});

it('gives every store\'s report each store in its own days, the off ones marked, then one total per currency - a super admin\'s alone', function () {
    Rx::aDay();
    $today = reportsToday();

    // An admin of every store is still not a super admin; nor is anyone else.
    Fx::actAsAdmin(['sa', 'eg', 'ae'], [OpsPermissions::REPORT_VIEW]);
    expect(fn () => Rx::preview('revenue', 'all', $today, $today))->toThrow(Unauthorized::class);
    reportsReader('sa', 'eg', 'ae');
    expect(fn () => Rx::preview('revenue', 'all', $today, $today))->toThrow(Unauthorized::class);

    $egypt = Fx::storeId('eg');
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $preview = Rx::preview('revenue', 'all', $today, $today);
    $rows = Rx::rows($preview);

    expect($preview->report->subtitle)->toBe("All stores · {$today} to {$today}")
        ->and($preview->report->filename)->toBe("revenue-all-{$today}-{$today}.xlsx")
        ->and(array_map(static fn (array $row): array => [$row[0], $row[2], $row[3], $row[4]], $rows))->toBe([
            ['Saudi Arabia', 'SAR', 1, '150.00'],
            ['Total', 'SAR', 1, '150.00'],
            ['Total', 'EGP', 0, '0.00'],
            ['Total', 'AED', 0, '0.00'],
        ])
        ->and(Rx::rows(Rx::preview('favourites', 'all')))->toBe([])
        ->and(Rx::preview('stock', 'all')->report->subtitle)->toStartWith('All stores · as at ');

    // The switched-off store is named, marked, wherever it has a row.
    $search = app(SearchLog::class);
    $search->record($egypt, 'ar', 'باب', 0);
    expect(array_map(static fn (array $row): string => (string) $row[0], Rx::rows(Rx::preview('searches', 'all', $today, $today))))->toBe(['Egypt (switched off)']);
});

it('reads a report only where the person holds ops.report.view, and Stock where they hold inventory.stock.manage too', function () {
    $today = reportsToday();
    reportsReader('sa');

    expect(Rx::rows(Rx::preview('revenue', Fx::storeId('sa'), $today, $today)))->toHaveCount(1)
        ->and(fn () => Rx::preview('revenue', Fx::storeId('ae'), $today, $today))->toThrow(Unauthorized::class)
        ->and(fn () => Rx::preview('stock', Fx::storeId('sa')))->toThrow(Unauthorized::class);

    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW, InventoryPermissions::STOCK_MANAGE], ['sa']));
    expect(Rx::preview('stock', Fx::storeId('sa'))->report->title)->toBe('Stock');

    Fx::actAsStaff(Fx::staffWith([InventoryPermissions::STOCK_MANAGE, OpsPermissions::REPORT_DAILY], ['sa']));
    expect(fn () => Rx::preview('stock', Fx::storeId('sa')))->toThrow(Unauthorized::class);
});

it('lists each size\'s sales, named through Catalog, most sold first, then one total per currency', function () {
    $day = Rx::aDay();
    $today = reportsToday();
    $variants = app(CatalogApi::class)->variants($day['variants']);
    $product = (string) app(CatalogApi::class)->product($day['product'])?->nameEn;
    $size = static fn (string $id): string => $variants[$id]->values[0]->attribute->en.': '.$variants[$id]->values[0]->value->en;
    reportsReader();

    expect(Rx::rows(Rx::preview('product-sales', Fx::storeId('sa'), $today, $today)))->toBe([
        ['Saudi Arabia', $product, $size($day['variants'][0]), $variants[$day['variants'][0]]->code, 'SAR', 2, '200.00'],
        ['Saudi Arabia', $product, $size($day['variants'][1]), $variants[$day['variants'][1]]->code, 'SAR', 1, '50.00'],
        ['Total', null, null, null, 'SAR', 3, '250.00'],
    ])
        ->and($size($day['variants'][1]))->toEndWith(': 80 cm');
});

it('lists each size\'s stock as it stands now', function () {
    $ready = Cx::ready();
    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW, InventoryPermissions::STOCK_MANAGE], ['sa']));

    $rows = Rx::rows(Rx::preview('stock', Fx::storeId('sa')));
    $mine = array_values(array_filter($rows, static fn (array $row): bool => in_array($row[3], array_map(static fn ($variant): string => $variant->code, app(CatalogApi::class)->variants($ready['variants'])), true)));

    // In stock, returned extra, held, available; then limited by stock, low, out, archived.
    expect(array_map(static fn (array $row): array => [...array_slice($row, 4, 4), ...array_slice($row, 9)], $mine))->toBe([
        [100, 0, 0, 100, 'Yes', 'No', 'No', 'No'],
        [100, 0, 0, 100, 'Yes', 'No', 'No', 'No'],
    ])
        ->and(array_map(static fn (array $row): string => (string) $row[0], $mine))->toBe(['Saudi Arabia', 'Saudi Arabia']);
});

it('lists the searches per phrase in each store\'s own days, and refuses more than twelve months', function () {
    $search = app(SearchLog::class);
    $search->record(Fx::storeId('sa'), 'en', 'oak door', 3);
    $search->record(Fx::storeId('sa'), 'en', 'oak door', 0);
    $search->record(Fx::storeId('sa'), 'ar', 'باب', 0);
    $search->record(Fx::storeId('ae'), 'en', 'drawer', 1);
    $today = reportsToday();
    reportsReader();

    expect(Rx::rows(Rx::preview('searches', Fx::storeId('sa'), $today, $today)))->toBe([
        ['Saudi Arabia', 'English', 'oak door', 2, 1],
        ['Saudi Arabia', 'Arabic', 'باب', 1, 1],
    ])
        ->and(Rx::rows(Rx::preview('searches', Fx::storeId('sa'), '2025-11-01', '2026-10-31')))->toBeArray()
        ->and(fn () => Rx::preview('searches', Fx::storeId('sa'), '2025-11-01', '2026-11-01'))->toThrow(InvalidOpsInput::class);
});

it('lists the products customers saved, an archived one kept and marked', function () {
    $ready = Cx::ready();
    $other = Cx::ready();
    foreach ([$ready['customer'], $other['customer']] as $customer) {
        Fx::actAsCustomer($customer);
        app(SaveToWishlistHandler::class)->handle(new SaveToWishlist(Fx::storeId('sa'), $ready['product']));
    }
    Fx::actAsCustomer($other['customer']);
    app(SaveToWishlistHandler::class)->handle(new SaveToWishlist(Fx::storeId('sa'), $other['product']));
    Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($other['product'])));
    $name = static fn (string $product): string => (string) app(CatalogApi::class)->product($product)?->nameEn;
    reportsReader();

    expect(Rx::rows(Rx::preview('favourites', Fx::storeId('sa'))))->toBe([
        ['Saudi Arabia', $name($ready['product']), 'No', 2],
        ['Saudi Arabia', $name($other['product']), 'Yes', 1],
    ]);
});

it('refuses days that are not days, a last day before the first, a grouping not day or month, a report or store that is not one', function (string $report, string $store, ?string $from, ?string $to, ?string $by) {
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    expect(fn () => Rx::preview($report, $store === 'sa' ? Fx::storeId('sa') : $store, $from, $to, $by))->toThrow(InvalidOpsInput::class);
})->with([
    'no first day' => ['revenue', 'sa', null, '2026-10-01', null],
    'not a date' => ['revenue', 'sa', '2026-02-30', '2026-03-01', null],
    'another form' => ['revenue', 'sa', '01/10/2026', '2026-10-02', null],
    'last before first' => ['product-sales', 'sa', '2026-10-02', '2026-10-01', null],
    'by week' => ['revenue', 'sa', '2026-10-01', '2026-10-02', 'week'],
    'no such report' => ['profit', 'sa', '2026-10-01', '2026-10-02', null],
    'no such store' => ['revenue', '01jbxqz2y8k4n6p9r3t5v7w1xa', '2026-10-01', '2026-10-02', null],
    'not an id' => ['revenue', 'nope', '2026-10-01', '2026-10-02', null],
]);

it('shows each sheet\'s first 50 rows in the preview, and says there are more', function () {
    $search = app(SearchLog::class);
    foreach (range(1, 51) as $index) {
        $search->record(Fx::storeId('sa'), 'en', "phrase {$index}", 1);
    }
    $today = reportsToday();
    reportsReader();

    $preview = Rx::preview('searches', Fx::storeId('sa'), $today, $today);

    expect(Rx::rows($preview))->toHaveCount(50)
        ->and($preview->more)->toBe([true]);
});

it('writes the reader\'s language: Arabic headers and words, the sheet right to left', function () {
    Rx::aDay();
    $today = reportsToday();
    $staff = reportsReader();
    DB::table('access.staff_users')->where('id', $staff)->update(['locale' => 'ar']);

    $file = Rx::download('revenue', Fx::storeId('sa'), $today, $today);
    $sheet = $file['sheets']['الإيرادات'];

    expect(array_keys($file['sheets']))->toBe(['الإيرادات', 'مبالغ مستردة منتظرة', 'حسب الفئة'])
        ->and($sheet[0])->toBe(['الإيرادات'])
        ->and($sheet[1][0])->toBe("السعودية · من {$today} إلى {$today}")
        ->and($sheet[6])->toBe(['المتجر', 'اليوم', 'العملة', 'الطلبات', 'الإيراد دون الضريبة', 'الإيراد إذا تمت المبالغ المستردة المنتظرة', 'ضريبة القيمة المضافة', 'الشحن', 'المبالغ المستردة', 'مبالغ مستردة منتظرة'])
        ->and($sheet[7][6])->toBe('بانتظار وحدة المبيعات (ضريبة المبالغ المستردة)')
        ->and($file['xml']['sheet1'])->toContain('rightToLeft="true"');
});

it('writes numbers as numbers, amounts with two decimals, days as dates, and a phrase starting with "=" as text', function () {
    Rx::aDay();
    app(SearchLog::class)->record(Fx::storeId('sa'), 'en', '=HYPERLINK("http://x.test","y")', 0);
    $today = reportsToday();
    reportsReader();

    $revenue = Rx::download('revenue', Fx::storeId('sa'), $today, $today);
    $row = $revenue['sheets']['Revenue'][7];
    $searches = Rx::download('searches', Fx::storeId('sa'), $today, $today);

    expect($revenue['filename'])->toBe("revenue-sa-{$today}-{$today}.xlsx")
        ->and($revenue['sheets']['Revenue'][6][0])->toBe('Store')
        ->and($row[0])->toBe('Saudi Arabia')
        ->and($row[1])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($row[1]->format('Y-m-d'))->toBe($today)
        ->and([$row[3], $row[4], $row[8], $row[9]])->toBe([1, 150, 100, 50])
        ->and($revenue['xml']['styles'])->toContain('<xf numFmtId="4"')
        ->and($revenue['xml']['styles'])->toContain('formatCode="yyyy-mm-dd"')
        ->and($revenue['xml']['sheet1'])->toContain('rightToLeft="false"')
        ->and($searches['sheets']['Searches'][4])->toBe(['Store', 'Language', 'Search', 'Searches', 'With no results'])
        ->and($searches['sheets']['Searches'][5][2])->toBe('=HYPERLINK("http://x.test","y")')
        ->and($searches['xml']['sheet1'])->not->toContain('<f>');
});

/**
 * Feedback answering `$products` favourites a thousand at a time, counting the pages asked: the size of the
 * read is the test's, not the database's.
 */
function reportsManyFavourites(int $products): stdClass
{
    $asked = new stdClass;
    $asked->pages = 0;
    app()->instance(FeedbackApi::class, new readonly class($products, $asked) implements FeedbackApi
    {
        public function __construct(private int $products, private stdClass $asked) {}

        public function favouriteCounts(StoreId $store, ?string $after = null, int $limit = 100): FavouriteCountsDto
        {
            $this->asked->pages++;
            $from = $after === null ? 0 : (int) $after;
            $rows = [];
            for ($index = $from; $index < min($from + $limit, $this->products); $index++) {
                $rows[] = new FavouriteCountDto(sprintf('01jbxqz2y8k4n6p9r3%08d', $index), $this->products - $index);
            }

            return new FavouriteCountsDto($rows, $from + $limit < $this->products ? (string) ($from + $limit) : null);
        }

        public function reviewForMessage(string $reviewId): ?ReviewForMessageDto
        {
            return null;
        }

        public function questionForMessage(string $questionId): ?QuestionForMessageDto
        {
            return null;
        }

        public function counts(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): FeedbackCountsDto
        {
            return new FeedbackCountsDto(0, 0, 0);
        }
    });

    return $asked;
}

it('reads the rows as the file is written: the preview asks one page, and 30,000 rows never sit in memory at once', function () {
    $asked = reportsManyFavourites(30_000);
    reportsReader();

    $preview = Rx::preview('favourites', Fx::storeId('sa'));
    expect([count(Rx::rows($preview)), $preview->more, $asked->pages])->toBe([50, [true], 1]);

    $asked->pages = 0;
    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $written = app(DownloadReportHandler::class)->handle(new DownloadReport('favourites', Fx::storeId('sa')));
    // What writing took above what was there before - a page of a thousand rows at a time and OpenSpout's
    // buffers: 1.7 MB measured for 12,000 rows and for 30,000 alike (2026-10-30). The 30,000 rows held as
    // PHP arrays take 6.7 MB.
    $peak = memory_get_peak_usage() - $before;
    $rows = Rx::read($written)['sheets']['Favourites'];

    // The title, its line, one note, a blank row, the headers - then every product, most saved first.
    expect(count($rows))->toBe(5 + 30_000)
        ->and($rows[5])->toBe(['Saudi Arabia', '', 'No', 30_000])
        ->and($rows[5 + 29_999][3])->toBe(1)
        ->and($asked->pages)->toBe(30)
        ->and($peak)->toBeLessThan(4 * 1024 * 1024);
});
