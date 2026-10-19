<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Public\Contracts\ProductStoreSection;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSale;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSaleHandler;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Application\Query\ListCategoryDiscounts\DiscountRow;
use Modules\Pricing\Application\Query\ListCategoryDiscounts\ListCategoryDiscounts;
use Modules\Pricing\Application\Query\ListCategoryDiscounts\ListCategoryDiscountsHandler;
use Modules\Pricing\Application\Query\ListPrices\ListPrices;
use Modules\Pricing\Application\Query\ListPrices\ListPricesHandler;
use Modules\Pricing\Application\Query\ListPrices\PriceRow;
use Modules\Pricing\Application\Query\ListSaleProducts\ListSaleProducts;
use Modules\Pricing\Application\Query\ListSaleProducts\ListSaleProductsHandler;
use Modules\Pricing\Application\Query\ListSales\ListSales;
use Modules\Pricing\Application\Query\ListSales\ListSalesHandler;
use Modules\Pricing\Application\Query\ListSales\SaleRow;
use Modules\Pricing\Application\Query\ListWholesale\ListWholesale;
use Modules\Pricing\Application\Query\ListWholesale\ListWholesaleHandler;
use Modules\Pricing\Application\Query\NeedsAPrice\NeedsAPrice;
use Modules\Pricing\Application\Query\NeedsAPrice\NeedsAPriceHandler;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsFor;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsForHandler;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapSize;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewCategoryDiscount;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewCategoryDiscountHandler;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewProduct;
use Modules\Pricing\Application\Query\PricingActions\PricingActionsForReader;
use Modules\Pricing\Application\Query\PricingActions\StorePricingActions;
use Modules\Pricing\Application\Query\ViewCategoryDiscount\ViewCategoryDiscount;
use Modules\Pricing\Application\Query\ViewCategoryDiscount\ViewCategoryDiscountHandler;
use Modules\Pricing\Application\Query\ViewProductPrices\ProductStorePrices;
use Modules\Pricing\Application\Query\ViewProductPrices\ViewProductPrices;
use Modules\Pricing\Application\Query\ViewProductPrices\ViewProductPricesHandler;
use Modules\Pricing\Application\Query\ViewSale\ViewSale;
use Modules\Pricing\Application\Query\ViewSale\ViewSaleHandler;
use Modules\Pricing\Application\Query\ViewSizePrices\ReachingDiscount;
use Modules\Pricing\Application\Query\ViewSizePrices\ReachingSale;
use Modules\Pricing\Application\Query\ViewSizePrices\ViewSizePrices;
use Modules\Pricing\Application\Query\ViewSizePrices\ViewSizePricesHandler;
use Modules\Pricing\Domain\Exception\CategoryDiscountNotFound;
use Modules\Pricing\Domain\Exception\SaleNotFound;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Infrastructure\Catalog\ProductPricesSection;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Pricing's reads for stage 5's screens (pricing.md §4.3.3; screens step 2): the Prices list and a size's
| drawer, a product's prices in every store, the Offers list and one offer, the category discounts and
| their preview, the note before saving a sale, the Wholesale Prices list, what the screens may offer,
| and the names Sync's report gives sales and discounts - each a fixed few queries however many rows.
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-14 10:00:00+00');
    Queue::fake();
    app()->setLocale('en');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * A ready product named for the order, switched on in KSA unless told not.
 *
 * @param  list<string>  $sizes
 * @return array{product: string, variants: list<string>, width: string}
 */
function screenProduct(string $nameEn, array $sizes = ['60 cm'], ?string $category = null, bool $on = true): array
{
    $ready = Px::ready($sizes, $category);
    DB::table('catalog.products')->where('id', $ready['product'])->update(['name_en' => $nameEn]);

    if ($on) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true)));
    }

    return $ready;
}

/**
 * A category named exactly so, for the paths and descriptions.
 */
function screenCategory(string $nameEn, ?string $parent = null): string
{
    $id = Px::category($nameEn, $parent);
    DB::table('catalog.categories')->where('id', $id)->update(['name_en' => $nameEn]);

    return $id;
}

/**
 * The store sells these sizes wholesale from this minimum (and up to this maximum), retail too unless told not.
 *
 * @param  list<string>  $sizes
 */
function screenWholesale(string $product, array $sizes, int $minimum, ?int $maximum = null, bool $retail = true): void
{
    Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(
        Rx::storeId('sa'),
        $product,
        array_fill_keys($sizes, ['retail' => $retail, 'wholesale' => true]),
        1,
        null,
        $minimum,
        $maximum,
    )));
}

/**
 * @param  array<int, string>  $bands
 */
function screenBands(string $size, array $bands): void
{
    Fx::asSystem(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices(Rx::storeId('sa'), $size, $bands)));
}

/**
 * @param  list<string>  $sizes
 */
function screenSale(array $sizes, ?string $percent = null, ?string $price = null, ?string $startsAt = null, ?string $endsAt = null, bool $alwaysWins = false, string $store = 'sa', ?string $product = null): string
{
    return Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId($store), $product, $sizes, $percent, $price, $startsAt, $endsAt, $alwaysWins)));
}

/**
 * @param  list<string>  $leftOut
 */
function screenDiscount(string $category, ?string $percent = null, ?string $amount = null, string $lines = 'BOTH', ?string $startsAt = null, ?string $endsAt = null, bool $alwaysWins = false, array $leftOut = []): string
{
    return Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('sa'), $category, $percent, $amount, $lines, $startsAt, $endsAt, $alwaysWins, $leftOut)));
}

/**
 * Items by a key of theirs.
 *
 * @template T of object
 *
 * @param  list<T>  $items
 * @param  callable(T): string  $key
 * @return array<string, T>
 */
function screenBy(array $items, callable $key): array
{
    $by = [];

    foreach ($items as $item) {
        $by[$key($item)] = $item;
    }

    return $by;
}

/**
 * The KSA store's currency sign, as every amount is written with it.
 */
function screenSign(): string
{
    return (app(PlatformApi::class)->store(StoreId::fromString(Rx::storeId('sa'))) ?? throw new LogicException('No store.'))->currencySymbol('en');
}

/**
 * How many queries a read asks, once warm - Pricing's and Catalog's own, never the cache's.
 */
function screenQueries(callable $read): int
{
    $read();
    $queries = Cx::recordQueries();
    $read();

    return count($queries);
}

describe('the Prices list (P1)', function () {
    it('lists the sizes switched on and those priced though switched off, by product name then size, with each row\'s facts', function () {
        $b = screenProduct('Bravo', ['60 cm', '80 cm']);
        $a = screenProduct('Alpha');
        $off = screenProduct('Charlie', on: false);
        Rx::set('sa', [$b['variants'][0] => '100', $off['variants'][0] => '50']);
        screenWholesale($b['product'], [$b['variants'][0]], 10);
        screenBands($b['variants'][0], [10 => '90', 50 => '80']);
        screenSale([$b['variants'][0]], percent: '10', endsAt: '2026-10-30 00:00:00+00', alwaysWins: true);
        Cx::actAsStaffWith([PricingPermissions::WHOLESALE_EDIT], ['sa']);

        $list = app(ListPricesHandler::class)->handle(new ListPrices(Rx::storeId('sa')));
        $rows = screenBy($list->rows, static fn (PriceRow $row): string => $row->size->variantId);
        $priced = $rows[$b['variants'][0]];

        expect(array_map(static fn (PriceRow $row): string => $row->size->variantId, $list->rows))->toBe([$a['variants'][0], $b['variants'][0], $b['variants'][1], $off['variants'][0]])
            ->and($list->total)->toBe(4)
            ->and($list->next)->toBeNull()
            ->and([$priced->retail?->price->minor, $priced->retail?->source, $priced->retail?->price->text])->toBe([10000, 'STAFF', '100.00 '.screenSign()])
            ->and([$priced->today?->price->minor, $priced->today?->before?->minor, $priced->today?->why, $priced->today?->alwaysWins])->toBe([9000, 10000, 'SALE', true])
            ->and($priced->today?->endsAt)->toBe('2026-10-30T00:00:00+00:00')
            ->and([$priced->bands, $priced->keptNotSoldWholesale, $priced->wholesaleOnly])->toBe([2, false, false])
            ->and([$priced->size->values, $priced->size->switchedOn, $priced->size->archived])->toBe([['60 cm'], true, false])
            ->and([$rows[$b['variants'][1]]->retail, $rows[$b['variants'][1]]->today])->toBe([null, null])
            // Priced here though switched off: listed, marked.
            ->and($rows[$off['variants'][0]]->size->switchedOn)->toBeFalse();
    });

    it('keeps its filters - No Price, On Sale Now, Has Wholesale Prices - and its search, Showing n of total', function () {
        $sold = screenProduct('Sold', ['60 cm', '80 cm']);
        $plain = screenProduct('Plain');
        $none = screenProduct('Nothing');
        Rx::set('sa', [$sold['variants'][0] => '100', $sold['variants'][1] => '100', $plain['variants'][0] => '100']);
        screenSale([$sold['variants'][0]], price: '80');
        // Scheduled: not on sale now.
        screenSale([$sold['variants'][1]], price: '80', startsAt: '2026-10-20 00:00:00+00');
        screenWholesale($plain['product'], [$plain['variants'][0]], 5);
        screenBands($plain['variants'][0], [5 => '90']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $ids = static fn (string $filter, ?string $search = null): array => array_map(static fn (PriceRow $row): string => $row->size->variantId, app(ListPricesHandler::class)->handle(new ListPrices(Rx::storeId('sa'), $search, $filter))->rows);

        expect($ids(ListPrices::NO_PRICE))->toBe([$none['variants'][0]])
            ->and($ids(ListPrices::ON_SALE_NOW))->toBe([$sold['variants'][0]])
            ->and($ids(ListPrices::HAS_WHOLESALE))->toBe([$plain['variants'][0]])
            ->and($ids(ListPrices::ALL, 'plain'))->toBe([$plain['variants'][0]])
            ->and(Rx::invalid(fn () => app(ListPricesHandler::class)->handle(new ListPrices(Rx::storeId('sa'), filter: 'CHEAP')))?->attribute)->toBe('filter');

        Cx::actAsStaffWith(['inventory.stock.manage'], ['sa']);

        expect(fn () => app(ListPricesHandler::class)->handle(new ListPrices(Rx::storeId('sa'))))->toThrow(Unauthorized::class);
    });

    it('pages 50 at a time after the cursor, and asks the same few queries for 1 row as for many', function () {
        $first = screenProduct('Aa', ['1']);
        Rx::set('sa', [$first['variants'][0] => '10']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $read = static fn (): mixed => app(ListPricesHandler::class)->handle(new ListPrices(Rx::storeId('sa')));
        $one = screenQueries($read);

        $many = screenProduct('Bb', array_map('strval', range(2, 55)));
        Rx::set('sa', array_fill_keys($many['variants'], '20'));
        screenSale([$many['variants'][0]], percent: '10');
        $page = $read();
        $next = app(ListPricesHandler::class)->handle(new ListPrices(Rx::storeId('sa'), after: $page->next));

        expect([count($page->rows), $page->total, count($next->rows)])->toBe([50, 55, 5])
            ->and($next->next)->toBeNull()
            ->and(screenQueries($read))->toBe($one)
            ->and($one)->toBeLessThanOrEqual(12);
    });
});

describe('a size\'s drawer', function () {
    it('says its retail price and source, what one piece costs retail and wholesale and why, and everything reaching it, named', function () {
        $category = screenCategory('Kitchens');
        $ready = screenProduct('Drawer', ['60 cm'], $category);
        [$size] = $ready['variants'];
        Rx::set('sa', [$size => '100']);
        screenWholesale($ready['product'], [$size], 10);
        screenBands($size, [10 => '85']);
        $sale = screenSale([$size], price: '90', endsAt: '2026-10-30 00:00:00+00');
        $later = screenSale([$size], percent: '20', startsAt: '2026-11-01 00:00:00+00');
        $discount = screenDiscount($category, amount: '120', lines: 'RETAIL');
        $wholesaleOnly = screenDiscount($category, percent: '5', lines: 'WHOLESALE');
        Cx::actAsStaffWith([PricingPermissions::CATEGORY_DISCOUNT_MANAGE], ['sa']);
        $read = static fn () => app(ViewSizePricesHandler::class)->handle(new ViewSizePrices(Rx::storeId('sa'), $size));

        $view = $read();
        $sales = screenBy($view->sales, static fn (ReachingSale $item): string => $item->id);
        $discounts = screenBy($view->discounts, static fn (ReachingDiscount $item): string => $item->id);

        expect([$view->retail?->price->minor, $view->retail?->source])->toBe([10000, 'STAFF'])
            ->and([$view->todayRetail?->price->minor, $view->todayRetail?->why, $view->todayRetail?->sourceId])->toBe([9000, 'SALE', $sale])
            // Wholesale at its minimum: the band, 85, below the sale.
            ->and([$view->wholesaleQuantity, $view->todayWholesale?->price->minor, $view->todayWholesale?->why])->toBe([10, 8500, 'QUANTITY'])
            ->and([$sales[$sale]->state, $sales[$sale]->priceOnSize?->minor, $sales[$sale]->winsNow])->toBe(['RUNNING', 9000, true])
            ->and([$sales[$later]->state, $sales[$later]->priceOnSize?->minor, $sales[$later]->winsNow])->toBe(['SCHEDULED', 8000, false])
            // 120 off a price of 100 would leave below 0: skipped, and why.
            ->and([$discounts[$discount]->skipped, $discounts[$discount]->priceOnSize, $discounts[$discount]->categoryPath])->toBe(['zero_or_below', null, ['Kitchens']])
            ->and([$discounts[$wholesaleOnly]->skipped, $discounts[$wholesaleOnly]->priceOnSize?->minor, $discounts[$wholesaleOnly]->lines])->toBe([null, 9500, 'WHOLESALE'])
            ->and(array_map(static fn ($band): array => [$band->fromQuantity, $band->price->minor], $view->bands))->toBe([[10, 8500]])
            ->and([$view->wholesaleMinimum, $view->minimumChanged, $view->soldRetail, $view->soldWholesale])->toBe([10, false, true, true])
            ->and(screenQueries($read))->toBeLessThanOrEqual(15);

        // The minimum moved: the first band no longer starts at it.
        screenWholesale($ready['product'], [$size], 5);

        expect($read()->minimumChanged)->toBeTrue();
    });
});

describe('a product\'s prices in every store (the product page)', function () {
    it('answers each store the reader prices in, in one read for every card, and registers itself with Catalog', function () {
        $category = screenCategory('Kitchens');
        $ready = screenProduct('Drawer', ['60 cm', '80 cm'], $category);
        [$a, $b] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('eg'), $ready['product'], true)));
        Rx::set('sa', [$a => '100', $b => '120']);
        Rx::set('eg', [$a => '300']);
        $sale = screenSale([$a], percent: '10');
        screenDiscount($category, percent: '5');
        $stores = [Rx::storeId('sa'), Rx::storeId('eg'), Rx::storeId('ae')];
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa', 'eg']);
        $read = static fn (): array => app(ViewProductPricesHandler::class)->handle(new ViewProductPrices($ready['product'], [$a, $b], $stores));

        $cards = screenBy($read(), static fn (ProductStorePrices $item): string => $item->storeId);

        expect(array_keys($cards))->toBe([Rx::storeId('sa'), Rx::storeId('eg')])
            ->and([$cards[Rx::storeId('sa')]->mayEditPrices, $cards[Rx::storeId('sa')]->mayEditWholesale, $cards[Rx::storeId('sa')]->wired])->toBe([true, false, false])
            ->and(array_map(static fn ($size): ?int => $size->today?->price->minor, $cards[Rx::storeId('sa')]->sizes))->toBe([9000, 11400])
            ->and(array_map(static fn ($size): ?int => $size->retail?->price->minor, $cards[Rx::storeId('eg')]->sizes))->toBe([30000, null])
            ->and(array_map(static fn ($sale): string => $sale->id, $cards[Rx::storeId('sa')]->sales))->toBe([$sale])
            ->and($cards[Rx::storeId('eg')]->sales)->toBe([])
            ->and(array_map(static fn ($discount): array => $discount->categoryPath, $cards[Rx::storeId('sa')]->discounts))->toBe([['Kitchens']]);

        // The same few queries for one store as for two, and for a reader with none of the jobs, nothing read.
        $two = screenQueries($read);
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);
        $one = screenQueries($read);
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_VIEW], ['sa']);

        expect($two)->toBe($one)->toBeLessThanOrEqual(12)
            ->and($read())->toBe([]);

        // Through Catalog's registry: plain data, keyed by store, drawn by Pricing/ProductPrices.
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);
        // Resolved as Catalog resolves it, when the tab is read - as the person reading it.
        $section = app(ProductPricesSection::class);
        $platform = app(PlatformApi::class);
        $plain = $section->read($ready['product'], [$a, $b], [$platform->store(StoreId::fromString(Rx::storeId('sa'))) ?? throw new LogicException('No store.')]);

        expect($section)->toBeInstanceOf(ProductStoreSection::class)
            ->and($section->component())->toBe('Pricing/ProductPrices')
            ->and(array_keys($plain))->toBe([Rx::storeId('sa')])
            ->and($plain[Rx::storeId('sa')]['sizes'][0]['today']['price']['minor'] ?? null)->toBe(9000);
    });
});

describe('the Offers list (P2) and one offer', function () {
    it('orders by state - running ending soonest, scheduled starting soonest, ended last ended first - or newest added', function () {
        $ready = screenProduct('Drawer', ['60 cm', '80 cm', '100 cm']);
        [$a, $b, $c] = $ready['variants'];
        Rx::set('sa', [$a => '100', $b => '100', $c => '100']);
        $endsLate = screenSale([$a], percent: '10', endsAt: '2026-10-30 00:00:00+00');
        CarbonImmutable::setTestNow('2026-10-14 10:01:00+00');
        $endsSoon = screenSale([$b], percent: '10', endsAt: '2026-10-20 00:00:00+00');
        CarbonImmutable::setTestNow('2026-10-14 10:02:00+00');
        $noEnd = screenSale([$c], percent: '10');
        CarbonImmutable::setTestNow('2026-10-14 10:03:00+00');
        $startsLate = screenSale([$a], percent: '5', startsAt: '2026-12-01 00:00:00+00');
        CarbonImmutable::setTestNow('2026-10-14 10:04:00+00');
        $startsSoon = screenSale([$b], percent: '5', startsAt: '2026-11-01 00:00:00+00');
        CarbonImmutable::setTestNow('2026-10-14 11:00:00+00');
        $endedFirst = screenSale([$c], percent: '5', endsAt: '2026-10-14 12:00:00+00');
        CarbonImmutable::setTestNow('2026-10-14 12:00:00+00');
        $endedLast = screenSale([$c], percent: '5', endsAt: '2026-10-14 13:00:00+00');
        CarbonImmutable::setTestNow('2026-10-15 00:00:00+00');
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $ids = static fn (ListSales $query): array => array_map(static fn (SaleRow $row): string => $row->id, app(ListSalesHandler::class)->handle($query)->sales);

        expect($ids(new ListSales(Rx::storeId('sa'))))->toBe([$endsSoon, $endsLate, $noEnd, $startsSoon, $startsLate, $endedLast, $endedFirst])
            ->and($ids(new ListSales(Rx::storeId('sa'), order: 'NEWEST')))->toBe([$endedLast, $endedFirst, $startsSoon, $startsLate, $noEnd, $endsSoon, $endsLate])
            ->and($ids(new ListSales(Rx::storeId('sa'), state: 'SCHEDULED')))->toBe([$startsSoon, $startsLate])
            ->and($ids(new ListSales(Rx::storeId('sa'), state: 'ENDED', order: 'NEWEST')))->toBe([$endedLast, $endedFirst])
            ->and($ids(new ListSales(Rx::storeId('sa'), saleId: strtoupper($noEnd))))->toBe([$noEnd]);
    });

    it('takes the newest-added order from when each was added, never changed by a change', function () {
        $ready = screenProduct('Drawer');
        Rx::set('sa', [$ready['variants'][0] => '100']);
        $older = screenSale($ready['variants'], percent: '10', startsAt: '2026-10-20 00:00:00+00');
        CarbonImmutable::setTestNow('2026-10-14 11:00:00+00');
        $newer = screenSale($ready['variants'], percent: '5', startsAt: '2026-10-21 00:00:00+00');
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $ids = static fn (): array => array_map(static fn (SaleRow $row): string => $row->id, app(ListSalesHandler::class)->handle(new ListSales(Rx::storeId('sa'), order: 'NEWEST'))->sales);

        expect($ids())->toBe([$newer, $older]);

        CarbonImmutable::setTestNow('2026-10-14 12:00:00+00');
        Fx::asSystem(fn () => app(ChangeSaleHandler::class)->handle(new ChangeSale($older, '12', null, '2026-10-20 00:00:00+00', null, false)));

        expect($ids())->toBe([$newer, $older]);
    });

    it('says All Sizes or n of m of the sizes switched on now, the sizes archived since and with no price, keyset-paged', function () {
        $ready = screenProduct('Drawer', ['60 cm', '80 cm', '100 cm']);
        [$a, $b, $c] = $ready['variants'];
        Rx::set('sa', [$a => '100', $b => '100', $c => '100']);
        $all = screenSale([], percent: '10', startsAt: '2026-10-20 00:00:00+00', product: $ready['product']);
        $some = screenSale([$a, $b], percent: '5', startsAt: '2026-10-25 00:00:00+00');
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($c)));
        Rx::remove('sa', [$b]);
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $rows = screenBy(app(ListSalesHandler::class)->handle(new ListSales(Rx::storeId('sa')))->sales, static fn (SaleRow $item): string => $item->id);

        // An archived size no longer counts among those switched on: the two left are all of them.
        expect([$rows[$all]->allSizes, $rows[$all]->sizesOn, $rows[$all]->productSizesOn, $rows[$all]->archivedSince])->toBe([true, 2, 2, 1])
            ->and($rows[$all]->sizeNames)->toEqualCanonicalizing(['60 cm', '80 cm', '100 cm'])
            ->and($rows[$all]->unpricedSizeNames)->toBe(['80 cm'])
            ->and([$rows[$some]->allSizes, $rows[$some]->sizesOn, $rows[$some]->productSizesOn])->toBe([true, 2, 2])
            ->and($rows[$all]->productNameEn)->toBe('Drawer')
            ->and($rows[$all]->createdAt)->toBe('2026-10-14T10:00:00+00:00');

        $view = app(ViewSaleHandler::class)->handle(new ViewSale($all));

        expect($view->sale->id)->toBe($all)
            ->and(collect($view->sizes)->mapWithKeys(static fn ($size): array => [$size->name => [$size->archived, $size->retail?->minor, $size->salePrice?->minor]])->all())
            ->toEqual(['60 cm' => [false, 10000, 9000], '80 cm' => [false, null, null], '100 cm' => [true, 10000, 9000]]);
    });

    it('pages 50 at a time with the total, in a fixed few queries, and lists the products with sales for its filter', function () {
        $ready = screenProduct('Drawer');
        $other = screenProduct('Other');
        Rx::set('sa', [$ready['variants'][0] => '100', $other['variants'][0] => '100']);
        screenSale($ready['variants'], percent: '10');
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $read = static fn () => app(ListSalesHandler::class)->handle(new ListSales(Rx::storeId('sa')));
        $one = screenQueries($read);

        foreach (range(1, 54) as $day) {
            screenSale($ready['variants'], percent: '5', startsAt: CarbonImmutable::parse('2026-11-01 00:00:00+00')->addDays($day * 2)->toIso8601String(), endsAt: CarbonImmutable::parse('2026-11-01 00:00:00+00')->addDays($day * 2 + 1)->toIso8601String());
        }

        $page = $read();
        $next = app(ListSalesHandler::class)->handle(new ListSales(Rx::storeId('sa'), after: $page->next));
        $products = app(ListSaleProductsHandler::class)->handle(new ListSaleProducts(Rx::storeId('sa')));

        expect([count($page->sales), $page->total, count($next->sales), $next->next])->toBe([50, 55, 5, null])
            ->and(array_intersect(array_map(static fn (SaleRow $row): string => $row->id, $page->sales), array_map(static fn (SaleRow $row): string => $row->id, $next->sales)))->toBe([])
            ->and(screenQueries($read))->toBe($one)
            ->and($one)->toBeLessThanOrEqual(12)
            ->and(array_map(static fn ($product): string => $product->productId, $products->rows))->toBe([$ready['product']]);
    });
});

describe('the category discounts (P3) and their preview', function () {
    it('names each category with its path and each product left out, filters by state, pages with the total', function () {
        $kitchens = screenCategory('Kitchens');
        $cabinets = screenCategory('Cabinets', $kitchens);
        $out = screenProduct('Left', ['60 cm'], $cabinets);
        $running = screenDiscount($cabinets, percent: '10', leftOut: [$out['product']]);
        $scheduled = screenDiscount($kitchens, amount: '5', lines: 'RETAIL', startsAt: '2026-11-01 00:00:00+00');
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $list = app(ListCategoryDiscountsHandler::class)->handle(new ListCategoryDiscounts(Rx::storeId('sa')));
        $rows = screenBy($list->discounts, static fn (DiscountRow $item): string => $item->id);

        expect(array_keys($rows))->toBe([$running, $scheduled])
            ->and([$rows[$running]->categoryPath, $rows[$running]->leftOut, $rows[$running]->leftOutNames])->toBe([['Kitchens', 'Cabinets'], [$out['product']], ['Left']])
            ->and([$rows[$scheduled]->state, $rows[$scheduled]->amountOff?->text, $rows[$scheduled]->lines])->toBe(['SCHEDULED', '5.00 '.screenSign(), 'RETAIL'])
            ->and(array_map(static fn ($row): string => $row->id, app(ListCategoryDiscountsHandler::class)->handle(new ListCategoryDiscounts(Rx::storeId('sa'), 'RUNNING'))->discounts))->toBe([$running])
            ->and($list->total)->toBe(2);
    });

    it('previews 20 products a page with totals for all, the sizes with no price counted, and refuses a left-out product outside the category', function () {
        $category = screenCategory('Kitchens');
        $products = [];

        // Named so their order is their names', P01 to P23.
        foreach (range(1, 23) as $n) {
            $products[] = screenProduct(sprintf('P%02d', $n), ['60 cm', '80 cm'], $category);
        }

        foreach ($products as $product) {
            Rx::set('sa', [$product['variants'][0] => '100']);
        }

        $elsewhere = screenProduct('Elsewhere', ['60 cm'], screenCategory('Doors'));
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $preview = static fn (?string $after = null, ?string $leftOut = null) => app(PreviewCategoryDiscountHandler::class)->handle(new PreviewCategoryDiscount(Rx::storeId('sa'), $category, '25', null, 'BOTH', $leftOut === null ? [] : [$leftOut], after: $after));

        $first = $preview();
        $second = $preview($first->next);

        expect(array_map(static fn (PreviewProduct $product): string => $product->productId, $first->reached))->toBe(array_map(static fn (array $product): string => $product['product'], array_slice($products, 0, 20)))
            ->and(array_map(static fn (PreviewProduct $product): string => $product->productId, $second->reached))->toBe(array_map(static fn (array $product): string => $product['product'], array_slice($products, 20)))
            ->and([$first->reachedProducts, $first->reachedSizes, $first->skippedSizes, $first->unpricedSizes, $first->deepestBasisPoints])->toBe([23, 23, 0, 23, 2500])
            ->and($second->next)->toBeNull()
            ->and($first->reached[0]->sizes[0]->name)->toBe('60 cm')
            ->and([$preview(null, $products[0]['product'])->reachedProducts])->toBe([22])
            ->and(Rx::invalid(fn () => $preview(null, $elsewhere['product']))?->attribute)->toBe('left_out')
            ->and(Rx::invalid(fn () => screenDiscount($category, percent: '5', leftOut: [$elsewhere['product']]))?->attribute)->toBe('left_out');
    });
});

describe('the note before saving a sale', function () {
    it('says each size\'s new price, whether it wins today or at its start, its depth against the ceiling, and names what it overlaps', function () {
        $category = screenCategory('Kitchens');
        $ready = screenProduct('Drawer', ['60 cm', '80 cm'], $category);
        [$a, $b] = $ready['variants'];
        Rx::set('sa', [$a => '100', $b => '100']);
        $cheaper = screenSale([$b], price: '75', endsAt: '2026-10-20 00:00:00+00');
        screenDiscount($category, percent: '20', lines: 'RETAIL', alwaysWins: true, startsAt: '2026-11-01 00:00:00+00');
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);
        $note = static fn (?string $startsAt = null, ?string $percent = null, ?string $price = null) => app(OverlapsForHandler::class)->handle(new OverlapsFor(Rx::storeId('sa'), $ready['product'], [], $startsAt, null, null, $percent, $price));

        $now = $note(percent: '20');
        $sizes = screenBy($now->sizes, static fn (OverlapSize $size): string => $size->variantId);
        $deep = screenBy($note(percent: '40')->sizes, static fn (OverlapSize $size): string => $size->variantId);

        expect([$sizes[$a]->newPrice?->minor, $sizes[$a]->wins, $sizes[$a]->notWinning, $sizes[$a]->depthBasisPoints, $sizes[$a]->overCeiling])->toBe([8000, true, null, 2000, false])
            // Deeper than the 30% ceiling: said here, refused on Save.
            ->and([$deep[$a]->depthBasisPoints, $deep[$a]->overCeiling, $deep[$b]->wins])->toBe([4000, true, true])
            ->and([$sizes[$b]->wins, $sizes[$b]->notWinning])->toBe([false, 'lower_price'])
            ->and([$now->atStart, $now->ceilingPercent])->toBe([false, 30])
            ->and(array_map(static fn ($sale): ?string => $sale->description, $now->sales))->toBe(['75.00 '.screenSign().' · 80 cm'])
            ->and(array_map(static fn ($sale): string => $sale->id, $now->sales))->toBe([$cheaper])
            ->and(array_map(static fn ($discount): ?string => $discount->description, $now->discounts))->toBe(['Kitchens: 20% off']);

        // Scheduled within the "always wins" discount: judged at its start, the discount holds it.
        $later = $note('2026-11-02 00:00:00+00', price: '90');

        expect($later->atStart)->toBeTrue()
            ->and(array_map(static fn (OverlapSize $size): ?string => $size->notWinning, $later->sizes))->toBe(['always_wins', 'always_wins'])
            ->and($note()->sizes)->toBe([]);
    });
});

describe('the note for an offer being changed', function () {
    it('reads the offer\'s own product and sizes, even once a size is switched off, and never another store\'s offer', function () {
        $ready = screenProduct('Drawer', ['60 cm', '80 cm']);
        [$a, $b] = $ready['variants'];
        Rx::set('sa', [$a => '100', $b => '100']);
        $sale = screenSale([$a, $b], percent: '10', startsAt: '2026-10-20 00:00:00+00');
        Rx::set('eg', [$a => '100']);
        Rx::switchOn('eg', [$a]);
        $elsewhere = screenSale([$a], percent: '10', store: 'eg');
        // Switched off since it was added: adding would refuse it (Q13), changing it keeps it.
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], false, [$b])));
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);
        $note = static fn (?string $saleId, ?string $one = null, ?string $two = null) => app(OverlapsForHandler::class)->handle(new OverlapsFor(Rx::storeId('sa'), null, array_values(array_filter([$one, $two], static fn (?string $id): bool => $id !== null)), '2026-10-20 00:00:00+00', null, $saleId, '20'));

        $changing = $note($sale);

        expect($changing->variantIds)->toEqualCanonicalizing([$a, $b])
            ->and($changing->productId)->toBe($ready['product'])
            ->and(count($changing->sizes))->toBe(2)
            ->and(Rx::invalid(fn () => $note(null, $a, $b))?->variants)->toBe([$b])
            ->and(fn () => $note($elsewhere))->toThrow(SaleNotFound::class)
            ->and(fn () => $note(strtolower((string) Str::ulid())))->toThrow(SaleNotFound::class);
    });
});

describe('who may read them (§4.3.1, Q1)', function () {
    it('refuses a reader without the job in that store, and a store that is off to whoever may not switch stores', function () {
        $category = screenCategory('Kitchens');
        $ready = screenProduct('Drawer', ['60 cm'], $category);
        [$size] = $ready['variants'];
        Rx::set('sa', [$size => '100']);
        $sale = screenSale([$size], percent: '10');
        $discount = screenDiscount($category, percent: '5');
        // The same in Egypt, which goes off at the end.
        Rx::set('eg', [$size => '100']);
        Rx::switchOn('eg', [$size]);
        $egSale = screenSale([$size], percent: '10', store: 'eg');
        $egDiscount = Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('eg'), $category, '5', null, 'BOTH', null, null)));
        $egId = Rx::storeId('eg');
        $readsIn = static fn (string $store, string $saleId, string $discountId): array => [
            fn () => app(ViewSizePricesHandler::class)->handle(new ViewSizePrices($store, $size)),
            fn () => app(ViewSaleHandler::class)->handle(new ViewSale($saleId)),
            fn () => app(ViewCategoryDiscountHandler::class)->handle(new ViewCategoryDiscount($discountId)),
            fn () => app(ListWholesaleHandler::class)->handle(new ListWholesale($store)),
            fn () => app(ListCategoryDiscountsHandler::class)->handle(new ListCategoryDiscounts($store)),
            fn () => app(ListSaleProductsHandler::class)->handle(new ListSaleProducts($store)),
            fn () => app(ListPricesHandler::class)->handle(new ListPrices($store)),
            fn () => app(NeedsAPriceHandler::class)->handle(new NeedsAPrice($store)),
            fn () => app(PreviewCategoryDiscountHandler::class)->handle(new PreviewCategoryDiscount($store, $category, '5', null, 'BOTH')),
        ];
        $reads = $readsIn(Rx::storeId('sa'), $sale, $discount);

        // Pricing's jobs in another store only: refused here - an offer of this store too (403, not 404).
        Cx::actAsStaffWith([...PricingPermissions::jobs()], ['ae']);

        foreach ($reads as $read) {
            expect($read)->toThrow(Unauthorized::class);
        }

        expect(fn () => app(ViewSaleHandler::class)->handle(new ViewSale(strtolower((string) Str::ulid()))))->toThrow(SaleNotFound::class)
            ->and(fn () => app(ViewCategoryDiscountHandler::class)->handle(new ViewCategoryDiscount(strtolower((string) Str::ulid()))))->toThrow(CategoryDiscountNotFound::class);

        // A job here, but not the one the read takes: Needs a Price is Edit Prices and Sales', the preview
        // Manage Category Discounts'.
        Cx::actAsStaffWith([PricingPermissions::WHOLESALE_EDIT], ['sa']);

        expect($reads[7])->toThrow(Unauthorized::class)
            ->and($reads[8])->toThrow(Unauthorized::class)
            ->and($reads[0]()->size->variantId)->toBe($size);

        // Every job in Egypt, and Egypt off: refused to whoever may not switch stores.
        $staff = Fx::staffWith([...PricingPermissions::jobs()], ['eg']);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsStaff($staff);

        foreach ($readsIn($egId, $egSale, $egDiscount) as $read) {
            expect($read)->toThrow(StoreOff::class);
        }
    });
});

describe('the Wholesale Prices list (P4)', function () {
    it('lists the sizes with bands, their minimum, and marks kept-not-sold, no retail price and a moved minimum', function () {
        $sold = screenProduct('Alpha');
        $kept = screenProduct('Bravo');
        $unpriced = screenProduct('Charlie');
        Rx::set('sa', [$sold['variants'][0] => '100', $kept['variants'][0] => '100', $unpriced['variants'][0] => '100']);

        foreach ([$sold, $kept, $unpriced] as $product) {
            screenWholesale($product['product'], $product['variants'], 10, 100);
            screenBands($product['variants'][0], [10 => '90', 50 => '80']);
        }

        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Rx::storeId('sa'), $kept['product'], [$kept['variants'][0] => ['retail' => true, 'wholesale' => false]], 1, null, null, null)));
        Rx::remove('sa', [$unpriced['variants'][0]]);
        screenWholesale($sold['product'], $sold['variants'], 5, 100);
        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);

        $list = app(ListWholesaleHandler::class)->handle(new ListWholesale(Rx::storeId('sa')));
        $flags = array_map(static fn ($row): array => [$row->size->variantId, $row->keptNotSoldWholesale, $row->noRetailPrice, $row->minimumChanged], $list->rows);

        expect($flags)->toBe([
            [$sold['variants'][0], false, false, true],
            [$kept['variants'][0], true, false, false],
            [$unpriced['variants'][0], false, true, false],
        ])
            ->and(array_map(static fn ($band): string => $band->price->text, $list->rows[0]->bands))->toBe(['90.00 '.screenSign(), '80.00 '.screenSign()])
            ->and([$list->rows[0]->minimum, $list->rows[0]->maximum, $list->total])->toBe([5, 100, 3]);
    });
});

describe('what the screens may offer', function () {
    it('answers per store the jobs held, the ceiling, products, wired and off, with the reasons', function () {
        $staff = Fx::staffWith([PricingPermissions::PRICE_EDIT, CatalogPermissions::PRODUCT_VIEW], ['sa', 'eg']);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsStaff($staff);
        $actions = app(PricingActionsForReader::class);

        // An off store is never offered to someone who may not switch stores (Q1).
        expect(array_map(static fn ($store): string => $store->storeId, $actions->stores()))->toBe([Rx::storeId('sa')])
            ->and([$actions->forStore(Rx::storeId('sa'))?->mayEditPrices, $actions->forStore(Rx::storeId('sa'))?->mayEditWholesale, $actions->forStore(Rx::storeId('sa'))?->mayViewProducts])->toBe([true, false, true])
            ->and([$actions->forStore(Rx::storeId('sa'))?->ceilingPercent, $actions->forStore(Rx::storeId('sa'))?->retailPriceRefusal, $actions->forStore(Rx::storeId('sa'))?->maySetCeiling])->toBe([30, null, false])
            ->and($actions->reasons()->noPrice)->toBe('Add a price first.');

        app()->instance(StoreWiring::class, new class implements StoreWiring
        {
            public function isWired(StoreId $store): bool
            {
                return true;
            }

            public function wiredStores(): array
            {
                return array_map(static fn ($store): string => $store->id, app(PlatformApi::class)->allStores());
            }
        });
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $all = screenBy(app(PricingActionsForReader::class)->stores(), static fn (StorePricingActions $item): string => $item->storeId);

        expect($all[Rx::storeId('eg')]->off)->toBeTrue()
            ->and([$all[Rx::storeId('sa')]->wired, $all[Rx::storeId('sa')]->retailPriceRefusal, $all[Rx::storeId('sa')]->maySetCeiling])->toBe([true, 'The provider sets this price.', true]);
    });

    it('names Catalog\'s product page job as Catalog declares it', function () {
        expect(PricingActionsForReader::PRODUCT_VIEW)->toBe(CatalogPermissions::PRODUCT_VIEW);
    });
});

describe('sales and discounts named for Sync\'s report', function () {
    it('describes each in the reader\'s language, in the order asked, and says when one is gone', function () {
        $category = screenCategory('Kitchens');
        $ready = screenProduct('Drawer', ['60 cm', '80 cm'], $category);
        Rx::set('sa', array_fill_keys($ready['variants'], '100'));
        $percent = screenSale($ready['variants'], percent: '12.5');
        $price = screenSale([$ready['variants'][0]], price: '90');
        $discount = screenDiscount($category, amount: '20');
        $gone = strtolower((string) Str::ulid());
        $api = app(PricingApi::class);
        $queries = Cx::recordQueries();

        $sales = $api->salesNamed([strtoupper($percent), $gone, $price]);

        expect(array_map(static fn ($name): array => [$name->exists, $name->description], $sales))->toBe([
            [true, '−12.5% · 60 cm, 80 cm'],
            [false, null],
            [true, '90.00 '.screenSign().' · 60 cm'],
        ])
            ->and($sales[0]->id)->toBe(strtoupper($percent))
            ->and(count($queries))->toBeLessThanOrEqual(5)
            ->and(array_map(static fn ($name): ?string => $name->description, $api->discountsNamed([$discount, $gone])))->toBe(['Kitchens: 20.00 '.screenSign().' off', null]);

        app()->setLocale('ar');
        $arabic = (string) DB::table('catalog.categories')->where('id', $category)->value('name_ar');

        expect($api->discountsNamed([$discount])[0]->description)->toBe("{$arabic}: خصم 20.00 ".(app(PlatformApi::class)->store(StoreId::fromString(Rx::storeId('sa')))?->currencySymbol('ar') ?? ''))
            ->and($api->salesNamed([$percent])[0]->description)->toStartWith('−12.5% · ');
    });
});
