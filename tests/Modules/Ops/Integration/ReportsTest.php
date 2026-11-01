<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Modules\Ops\Application\Reports\ReportMoney;
use Modules\Ops\Application\Reports\ReportPeriod;
use Modules\Ops\Application\Reports\ReportWords;
use Modules\Ops\Application\Reports\RevenueSheets;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\ReportRangeRefused;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBack;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBackHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\MoneyDaysDto;
use Modules\Sales\Public\Dto\MoneyRecordMessageDto;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Modules\Sales\Public\Dto\ProductNetSalesPageDto;
use Modules\Sales\Public\Dto\ProductSalesPageDto;
use Modules\Sales\Public\Dto\ReturnMessageDto;
use Modules\Sales\Public\Dto\RevenueDayDto;
use Modules\Sales\Public\Dto\SalesCountsDto;
use Modules\Sales\Public\Dto\TransfersWaitingDto;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\ReportFixtures as Rx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The reports (ops.md §1.10, §3, §7, §8): revenue against the orders counted by hand - a cancelled or
| rejected order never counts, a refund comes off on the day its return was asked, the waiting ones in a
| second figure, VAT net of the refunds' VAT; every store's report with one total per currency and the switched-off
| stores marked; product sales, stock, searches, favourites; who may read which; the days refused.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    // The clock pinned to tomorrow's 09:30 UTC: never behind the real time (lesson 221), and never where the
    // stores' days differ - the next hour could land past midnight in Riyadh and before it in Egypt, so "today"
    // is one day for every store, the orders made and the report read.
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDay()->setTime(9, 30));
});

/** The English name of the category of a product made by the fixtures (a top category: its path is itself). */
function reportsCategoryName(string $productId): string
{
    $catalog = app(CatalogApi::class);
    $category = (string) $catalog->product($productId)?->categoryId;

    return $catalog->categoriesWithPaths([$category])[$category]->nameEn;
}

function reportsToday(): string
{
    return CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d');
}

/** The report is read a minute after the day's work: a range that includes today ends at the moment asked. */
function reportsAMinuteLater(): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
}

/** The search log stamps its own time (the database's): moved to a minute before the pinned clock. */
function reportsSearchedNow(): void
{
    DB::table('catalog.search_log')->update(['searched_at' => CarbonImmutable::now()->subMinute()]);
}

function reportsReader(string ...$stores): string
{
    $staff = Fx::staffWith([OpsPermissions::REPORT_VIEW], $stores === [] ? ['sa'] : array_values($stores));
    Fx::actAsStaff($staff);
    reportsAMinuteLater();

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
    $paid = (int) $order?->paid_minor;
    $time = CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d H:i');

    expect(array_map(static fn ($sheet): string => $sheet->name, $preview->report->sheets))->toBe(['Revenue', 'Money received', 'Waiting refunds', 'By category'])
        // Today is read until the moment asked, and every sheet says so.
        ->and(array_map(static fn ($sheet): string => $sheet->notes[0], $preview->report->sheets))->toBe(array_fill(0, 4, "The range includes today: it is counted until {$time}."))
        ->and([$preview->report->title, $preview->report->subtitle, $preview->report->locale, $preview->report->filename])
        ->toBe(['Revenue', "Saudi Arabia · {$today} to {$today}", 'en', "revenue-sa-{$today}-{$today}.xlsx"])
        // One order counts: 250.00 sold, 100.00 refunded, 50.00 waiting - 150.00, and 100.00 if the waiting go through.
        // Its VAT 37.50 less the 15.00 refunded is 22.50, and 15.00 if the waiting 7.50 goes through too.
        ->and(Rx::rows($preview))->toBe([
            ['Saudi Arabia', $today, 'SAR', 1, '150.00', '100.00', '22.50', '15.00', '0.00', '100.00', '50.00'],
            ['Total', null, 'SAR', 1, '150.00', '100.00', '22.50', '15.00', '0.00', '100.00', '50.00'],
        ])
        ->and([$goods, (int) $order?->vat_minor, (int) $order?->shipping_fee_minor])->toBe([250_00, 37_50, 0])
        ->and($preview->report->sheets[0]->notes)->toContain('VAT is the goods\' VAT less the VAT of the refunds of the returns asked that day: "VAT if the waiting refunds go through" takes the VAT of the waiting ones off too.')
        // The money received, by the day it arrived and by way; then the total per currency.
        ->and(Rx::rows($preview, 1))->toBe([
            ['Saudi Arabia', $today, 'SAR', 'Bank transfer', 1, $money($paid), 0, '0.00'],
            ['Total', null, 'SAR', 'Bank transfer', 1, $money($paid), 0, '0.00'],
        ])
        ->and($paid)->toBeGreaterThan(0)
        // The return still waiting, by return and order number - no customer - with its VAT.
        ->and(Rx::rows($preview, 2))->toBe([['Saudi Arabia', $today, $day['open'], $day['number'], 'SAR', '50.00', '7.50']])
        // By category, net of the refund, and the same figure if the waiting one goes through (owner, 2026-11-01).
        ->and(Rx::rows($preview, 3))->toBe([
            ['Saudi Arabia', reportsCategoryName($day['product']), 'SAR', '150.00', '100.00'],
            ['Total', null, 'SAR', '150.00', '100.00'],
        ])
        ->and($preview->report->sheets[3]->notes[1])->toStartWith('Revenue of each category');
});

it('adds the categories up to the Revenue sheet\'s total, with a completed return and a waiting one, no category and the moment asked', function () {
    $day = Rx::aDay();
    // Another category's product, sold whole; a product with no category; and an order after the moment asked.
    $other = Cx::ready();
    Cx::place(Cx::start($other['address'])->id);
    $bare = Cx::ready();
    Cx::place(Cx::start($bare['address'])->id);
    // Only a product not ready may have no category: taken back to a draft since.
    DB::table('catalog.products')->where('id', $bare['product'])->update(['stage' => 'DRAFT', 'category_id' => null]);
    $late = Cx::ready();
    $later = Cx::place(Cx::start($late['address'])->id);
    DB::table('sales.orders')->where('id', $later->id)->update(['placed_at' => CarbonImmutable::now()->addHours(2)]);
    $today = reportsToday();
    reportsReader();

    $preview = Rx::preview('revenue', Fx::storeId('sa'), $today, $today);
    $revenue = Rx::rows($preview);
    $revenueTotal = $revenue[count($revenue) - 1];
    $all = Rx::rows($preview, 3);
    $total = $all[count($all) - 1];
    $categories = array_slice($all, 0, -1);
    $byName = [];
    foreach ($categories as $row) {
        $byName[(string) $row[1]] = [$row[3], $row[4]];
    }

    expect($byName)->toEqualCanonicalizing([
        reportsCategoryName($day['product']) => ['150.00', '100.00'],
        reportsCategoryName($other['product']) => ['250.00', null],
        'No category' => ['250.00', null],
    ])
        // Most revenue first.
        ->and($categories[2][3])->toBe('150.00')
        // The sheet's total is the Revenue sheet's net total, and its waiting column agrees.
        ->and([$total[3], $total[4]])->toBe(['650.00', '600.00'])
        ->and([$total[3], $total[4]])->toBe([$revenueTotal[4], $revenueTotal[5]]);
});

it('takes a refund off its category on the day its return was asked, whatever day the order was placed', function () {
    $day = Rx::aDay();
    DB::table('sales.orders')->where('id', $day['delivered'])->update(['placed_at' => CarbonImmutable::now()->subDays(7)]);
    $today = reportsToday();
    reportsReader();

    $preview = Rx::preview('revenue', Fx::storeId('sa'), $today, $today);

    // Nothing sold today, 100.00 refunded, 50.00 waiting.
    expect(Rx::rows($preview, 3))->toBe([
        ['Saudi Arabia', reportsCategoryName($day['product']), 'SAR', '-100.00', '-150.00'],
        ['Total', null, 'SAR', '-100.00', '-150.00'],
    ])
        ->and(array_slice(Rx::rows($preview)[0], 4, 2))->toBe(['-100.00', '-150.00']);
});

it('gives every store\'s categories, in the reader\'s language, then one total per currency', function () {
    $day = Rx::aDay();
    $today = reportsToday();
    reportsAMinuteLater();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    DB::table('access.staff_users')->update(['locale' => 'ar']);
    cache()->flush();

    $preview = Rx::preview('revenue', 'all', $today, $today);
    $category = app(CatalogApi::class)->categoriesWithPaths([(string) app(CatalogApi::class)->product($day['product'])?->categoryId]);

    expect(Rx::rows($preview, 3))->toBe([
        ['السعودية', array_values($category)[0]->nameAr, 'SAR', '150.00', '100.00'],
        ['الإجمالي', null, 'SAR', '150.00', '100.00'],
        ['الإجمالي', null, 'EGP', '0.00', null],
        ['الإجمالي', null, 'AED', '0.00', null],
    ])
        ->and($preview->report->sheets[3]->notes)->toContain('كل المتاجر: كل متجر، ثم إجمالي واحد لكل عملة، دون تحويل أبدًا.');
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

    expect(Rx::preview('revenue', Fx::storeId('sa'), '2026-09-01', '2026-09-30')->report->sheets[0]->notes[0])->toStartWith('Revenue is what')
        ->and(array_map(static fn (array $row): array => [$row[1], $row[3], $row[4], $row[5], $row[6], $row[7]], $byDay))
        ->toBe([['2026-09-03', 1, '250.00', null, '37.50', null], ['2026-09-20', 1, '250.00', null, '37.50', null], [null, 2, '500.00', null, '75.00', null]])
        ->and(array_map(static fn (array $row): array => [$row[1], $row[3], $row[4], $row[6]], $byMonth))->toBe([['2026-09', 2, '500.00', '75.00'], [null, 2, '500.00', '75.00']]);
});

it('gives every store\'s report each store in its own days, the off ones marked, then one total per currency - a super admin\'s alone', function () {
    Rx::aDay();
    $today = reportsToday();
    reportsAMinuteLater();

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
    reportsSearchedNow();
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
    reportsSearchedNow();
    $today = reportsToday();
    reportsReader();

    expect(Rx::rows(Rx::preview('searches', Fx::storeId('sa'), $today, $today)))->toBe([
        ['Saudi Arabia', 'English', 'oak door', 2, 1],
        ['Saudi Arabia', 'Arabic', 'باب', 1, 1],
    ])
        ->and(Rx::rows(Rx::preview('searches', Fx::storeId('sa'), '2025-11-01', '2026-10-31')))->toBeArray()
        ->and(fn () => Rx::preview('searches', Fx::storeId('sa'), '2025-11-01', '2026-11-01'))->toThrow(ReportRangeRefused::class);
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
    reportsSearchedNow();
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

    expect(array_keys($file['sheets']))->toBe(['الإيرادات', 'المبالغ المستلمة', 'مبالغ مستردة منتظرة', 'حسب الفئة'])
        ->and($sheet[0])->toBe(['الإيرادات'])
        ->and($sheet[1][0])->toBe("السعودية · من {$today} إلى {$today}")
        ->and($sheet[2][0])->toStartWith('تشمل الفترة اليوم: يُحتسب حتى ')
        ->and($sheet[7])->toBe(['المتجر', 'اليوم', 'العملة', 'الطلبات', 'الإيراد دون الضريبة', 'الإيراد بعد خصم المبالغ المستردة المنتظرة', 'ضريبة القيمة المضافة', 'الضريبة بعد خصم المبالغ المستردة المنتظرة', 'الشحن', 'المبالغ المستردة', 'مبالغ مستردة منتظرة'])
        // VAT net of the refund's, and if the waiting one goes through: figures, never words.
        ->and([$sheet[8][6], $sheet[8][7]])->toBe([22.5, 15])
        ->and($file['xml']['sheet1'])->toContain('rightToLeft="true"');
});

it('writes numbers as numbers, amounts with two decimals, days as dates, and a phrase starting with "=" as text', function () {
    Rx::aDay();
    app(SearchLog::class)->record(Fx::storeId('sa'), 'en', '=HYPERLINK("http://x.test","y")', 0);
    reportsSearchedNow();
    $today = reportsToday();
    reportsReader();

    $revenue = Rx::download('revenue', Fx::storeId('sa'), $today, $today);
    // The title, its line, four notes (until, revenue, waiting, VAT), a blank row, the headers: row 8.
    $row = $revenue['sheets']['Revenue'][8];
    $searches = Rx::download('searches', Fx::storeId('sa'), $today, $today);

    expect($revenue['filename'])->toBe("revenue-sa-{$today}-{$today}.xlsx")
        ->and($revenue['sheets']['Revenue'][7][0])->toBe('Store')
        ->and($row[0])->toBe('Saudi Arabia')
        ->and($row[1])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($row[1]->format('Y-m-d'))->toBe($today)
        ->and([$row[3], $row[4], $row[6], $row[7], $row[9], $row[10]])->toBe([1, 150, 22.5, 15, 100, 50])
        ->and($revenue['xml']['styles'])->toContain('<xf numFmtId="4"')
        ->and($revenue['xml']['styles'])->toContain('formatCode="yyyy-mm-dd"')
        ->and($revenue['xml']['sheet1'])->toContain('rightToLeft="false"')
        ->and($searches['sheets']['Searches'][5])->toBe(['Store', 'Language', 'Search', 'Searches', 'With no results'])
        ->and($searches['sheets']['Searches'][6][2])->toBe('=HYPERLINK("http://x.test","y")')
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

it('refuses years before 2020 or after next year, more than five years, and more than twelve months of searches', function (string $report, string $from, string $to, string $rule) {
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $next = (string) ((int) CarbonImmutable::now()->format('Y') + 1);
    $day = static fn (string $value): string => str_replace('NEXT', $next, $value);

    try {
        Rx::preview($report, Fx::storeId('sa'), $day($from), $day($to));
        $refused = null;
    } catch (ReportRangeRefused $error) {
        $refused = $error->context();
    }

    expect($refused)->toBe(['attribute' => $rule === 'years' && $from === '2019-12-31' ? 'from' : 'to', 'rule' => $rule]);
})->with([
    'before 2020' => ['revenue', '2019-12-31', '2020-01-02', 'years'],
    'after next year' => ['revenue', 'NEXT-12-01', ((string) ((int) date('Y') + 2)).'-01-01', 'years'],
    'over five years' => ['product-sales', '2020-01-01', '2025-01-01', 'too_long'],
    'over twelve months of searches' => ['searches', '2025-01-01', '2026-01-01', 'searches_too_long'],
]);

it('reads five years at once, and the last day of next year', function () {
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $next = (int) CarbonImmutable::now()->format('Y') + 1;

    expect(Rx::rows(Rx::preview('product-sales', Fx::storeId('sa'), '2020-01-01', '2024-12-31')))->toBe([['Total', null, null, null, 'SAR', 0, '0.00']])
        ->and(Rx::rows(Rx::preview('revenue', Fx::storeId('sa'), "{$next}-12-01", "{$next}-12-31")))->toHaveCount(1);
});

it('never counts an order that came back', function () {
    $staff = Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::PAYMENT_RECORD], ['sa']);
    $ready = Cx::ready();
    $back = Cx::place(Cx::start($ready['address'])->id)->id;
    $kept = Cx::ready();
    Cx::place(Cx::start($kept['address'])->id);
    Fx::actAsStaff($staff);
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($back));
    app(RecordPaymentHandler::class)->handle(new RecordPayment($back, 'BANK_TRANSFER', 'T-2', null, reportsToday(), (string) Str::uuid()));
    app(ShipOrderHandler::class)->handle(new ShipOrder($back, otherCarrier: 'SMSA', trackingNumber: 'TRK-2'));
    app(MarkCameBackHandler::class)->handle(new MarkCameBack($back, [['variantId' => $ready['variants'][0], 'quantity' => 2], ['variantId' => $ready['variants'][1], 'quantity' => 1]]));
    $today = reportsToday();
    reportsReader();

    expect(DB::table('sales.orders')->where('id', $back)->value('status'))->toBe('UNDELIVERED')
        ->and(array_map(static fn (array $row): array => [$row[3], $row[4]], Rx::rows(Rx::preview('revenue', Fx::storeId('sa'), $today, $today))))->toBe([[1, '250.00'], [1, '250.00']]);
});

/**
 * Sales answering one day of revenue for every store asked, the same for each: the read the test controls.
 */
function reportsOneDayEach(string $day): void
{
    app()->instance(SalesReports::class, new readonly class($day) implements SalesReports
    {
        public function __construct(private string $day) {}

        public function orderForMessage(string $orderId, string $locale): ?OrderMessageDto
        {
            return null;
        }

        public function returnForMessage(string $returnId): ?ReturnMessageDto
        {
            return null;
        }

        public function moneyRecordForMessage(string $moneyRecordId): ?MoneyRecordMessageDto
        {
            return null;
        }

        public function counts(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): SalesCountsDto
        {
            throw new LogicException('Not read by the reports.');
        }

        public function moneyOn(StoreId $store, DateTimeImmutable $fromDay, DateTimeImmutable $toDay): MoneyDaysDto
        {
            return new MoneyDaysDto([], []);
        }

        public function moneyByDay(StoreId $store, DateTimeImmutable $fromDay, DateTimeImmutable $toDay): array
        {
            return [];
        }

        public function transfersWaiting(StoreId $store): TransfersWaitingDto
        {
            throw new LogicException('Not read by the reports.');
        }

        public function revenueByDay(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): array
        {
            $sar = static fn (int $minor): Money => Money::of($minor, 'SAR');

            return [new RevenueDayDto($this->day, 2, $sar(300_00), $sar(45_00), $sar(20_00), $sar(50_00), $sar(0), [], $sar(7_50), $sar(0))];
        }

        public function productSales(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after = null, int $limit = 100): ProductSalesPageDto
        {
            return new ProductSalesPageDto([], null);
        }

        public function productNetSales(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after = null, int $limit = 100): ProductNetSalesPageDto
        {
            return new ProductNetSalesPageDto([], null);
        }
    });
}

it('sums two stores of one currency into one total, never converted', function () {
    reportsOneDayEach('2026-09-10');
    $store = static fn (string $id, string $code, string $name): StoreDto => new StoreDto(
        $id, $code, new TranslatedTextDto($name, $name), 'SA', 'SAR', 2, null, new TranslatedTextDto('ر.س', 'SAR'), 1500, 'Asia/Riyadh', 1,
    );
    $period = ReportPeriod::of('2026-09-01', '2026-09-30', CarbonImmutable::now()->toDateTimeImmutable());
    $sheets = app(RevenueSheets::class)->sheets([$store('01jbxqz2y8k4n6p9r3t5v7w1xa', 'one', 'One'), $store('01jbxqz2y8k4n6p9r3t5v7w1xb', 'two', 'Two')], $period, false, new ReportWords(app('translator'), 'en'));
    $rows = [];
    foreach ($sheets[0]->rows as $row) {
        $rows[] = array_map(static fn (string|int|ReportMoney|null $value): string|int|null => $value instanceof ReportMoney ? $value->decimal() : $value, [$row[0], $row[2], $row[3], $row[4], $row[6], $row[7], $row[9]]);
    }

    expect($rows)->toBe([
        // 300.00 less 50.00 refunded; VAT 45.00 less the refund's 7.50; nothing waits.
        ['One', 'SAR', 2, '250.00', '37.50', null, '50.00'],
        ['Two', 'SAR', 2, '250.00', '37.50', null, '50.00'],
        ['Total', 'SAR', 4, '500.00', '75.00', null, '100.00'],
    ]);
});

it('reads a preview in a fixed number of queries', function () {
    config(['session.lottery' => [0, 100]]);
    Rx::aDay();
    $today = reportsToday();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    reportsAMinuteLater();
    $count = static function (string $report, string $store) use ($today): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Rx::preview($report, $store, $today, $today);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    // Warm the caches (the stores, the permissions), as a second request finds them.
    Rx::preview('revenue', 'all', $today, $today);

    $counts = [$count('revenue', Fx::storeId('sa')), $count('revenue', 'all'), $count('product-sales', Fx::storeId('sa'))];

    // Measured 2026-10-31, a one-day range. Revenue, one store: the reader (1), revenueByDay (4), moneyByDay
    // (1) = 6, however many days. Every store: the reader, revenueByDay (4 for the store with orders, 2 for
    // each of the two without), moneyByDay for each (3) = 12. Product sales: the reader, productSales (1),
    // the names (3) = 5. By category adds, measured 2026-11-01: for a store with sales productNetSales (4), its
    // products (1) and their categories' paths (1) = 6; for a store without, productNetSales (1).
    expect($counts)->toBe([12, 20, 5]);
});

it('reads money received day by day over any range in one read, and sums a month\'s days when asked by month', function () {
    // Today's payment, and two copies on the 3rd and the 17th of the month before last (the records are never
    // changed, so the copies are inserted as Sales' own test does).
    Rx::aDay();
    $payment = (array) DB::table('sales.payments')->first();
    $month = CarbonImmutable::now('Asia/Riyadh')->startOfMonth()->subMonthsNoOverflow(2)->format('Y-m');
    foreach (['-03', '-17'] as $day) {
        DB::table('sales.payments')->insert([...$payment, 'id' => strtolower((string) Str::ulid()), 'happened_on' => $month.$day, 'request_id' => (string) Str::uuid()]);
    }
    $amount = static fn (int $times): string => number_format($times * (int) $payment['amount_minor'] / 100, 2, '.', '');
    config(['session.lottery' => [0, 100]]);
    $today = reportsToday();
    reportsReader();
    $from = CarbonImmutable::now('Asia/Riyadh')->subDays(119)->format('Y-m-d');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $byDay = Rx::preview('revenue', Fx::storeId('sa'), $from, $today);
    $rows = Rx::rows($byDay, 1);
    DB::disableQueryLog();
    $moneyReads = count(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains((string) $query['query'], 'sales.payments') || str_contains((string) $query['query'], '"sales"."payments"')));
    $byMonth = Rx::preview('revenue', Fx::storeId('sa'), $from, $today, 'month');
    $row = static fn (array $one): array => [$one[1], $one[3], $one[4], $one[5]];

    // 120 days, each day with money its own row - never by month unless asked, and no note about it.
    expect($byDay->report->sheets[1]->columns[1]->key)->toBe('day')
        ->and(array_map($row, $rows))->toBe([
            ["{$month}-03", 'Bank transfer', 1, $amount(1)],
            ["{$month}-17", 'Bank transfer', 1, $amount(1)],
            [$today, 'Bank transfer', 1, $amount(1)],
            [null, 'Bank transfer', 3, $amount(3)],
        ])
        // One read of the money, however many days.
        ->and($moneyReads)->toBe(1)
        ->and(array_slice($byDay->report->sheets[1]->notes, 1))->toBe(['The money that moved, by the day it moved: payments in and refunds out, by way. Test payments never count.'])
        // By month: the month's two days summed.
        ->and($byMonth->report->sheets[1]->columns[1]->key)->toBe('month')
        ->and(array_map($row, Rx::rows($byMonth, 1)))->toBe([
            [$month, 'Bank transfer', 2, $amount(2)],
            [substr($today, 0, 7), 'Bank transfer', 1, $amount(1)],
            [null, 'Bank transfer', 3, $amount(3)],
        ])
        ->and((int) $payment['amount_minor'])->toBe(287_50);
});
