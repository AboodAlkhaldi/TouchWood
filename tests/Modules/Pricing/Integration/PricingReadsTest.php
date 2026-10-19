<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Prices\NeedsAPriceCount;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Application\Query\ListCategoryDiscounts\ListCategoryDiscounts;
use Modules\Pricing\Application\Query\ListCategoryDiscounts\ListCategoryDiscountsHandler;
use Modules\Pricing\Application\Query\ListSales\ListSales;
use Modules\Pricing\Application\Query\ListSales\ListSalesHandler;
use Modules\Pricing\Application\Query\NeedsAPrice\NeedsAPrice;
use Modules\Pricing\Application\Query\NeedsAPrice\NeedsAPriceHandler;
use Modules\Pricing\Application\Query\NeedsAPrice\UnpricedList;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsFor;
use Modules\Pricing\Application\Query\OverlapsFor\OverlapsForHandler;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewCategoryDiscount;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewCategoryDiscountHandler;
use Modules\Pricing\Application\Query\PriceViews\SizeView;
use Modules\Pricing\Application\Query\ViewCategoryDiscount\ViewCategoryDiscount;
use Modules\Pricing\Application\Query\ViewCategoryDiscount\ViewCategoryDiscountHandler;
use Modules\Pricing\Application\Query\ViewPrices\PriceLine;
use Modules\Pricing\Application\Query\ViewPrices\ViewPrices;
use Modules\Pricing\Application\Query\ViewPrices\ViewPricesHandler;
use Modules\Pricing\Domain\Exception\StoreOff;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| The screens' reads (pricing.md §1.5, §1.9, §3; step 7b): Needs a Price and its count, a discount's
| preview before saving and on its page, the lists of sales and discounts, a size's prices, the note
| before saving naming discounts - each for the jobs §3 names, an off store for whoever may switch
| stores.
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-14 10:00:00+00');
    Queue::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<string>  $sizes
 * @return array{product: string, variants: list<string>, width: string}
 */
function readsOn(array $sizes = ['60 cm', '80 cm'], ?string $category = null): array
{
    $ready = Px::ready($sizes, $category);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true)));

    return $ready;
}

describe('Needs a Price (§1.9)', function () {
    it('lists every size switched on with no retail price, and counts them, for Edit Prices and Sales', function () {
        [$priced, $unpriced] = readsOn()['variants'];
        Rx::set('sa', [$priced => '100']);
        $list = static fn (): array => array_map(static fn (SizeView $size): string => $size->variantId, Fx::asSystem(fn (): UnpricedList => app(NeedsAPriceHandler::class)->handle(new NeedsAPrice(Rx::storeId('sa'))))->rows);

        expect($list())->toBe([$unpriced]);

        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['sa']);

        expect(app(NeedsAPriceCount::class)->count())->toBe(1);

        Cx::actAsStaffWith([PricingPermissions::WHOLESALE_EDIT], ['sa']);

        expect(fn () => app(NeedsAPriceHandler::class)->handle(new NeedsAPrice(Rx::storeId('sa'))))->toThrow(Unauthorized::class)
            ->and(app(NeedsAPriceCount::class)->count())->toBe(0);
    });
});

describe('a discount\'s preview (§1.5)', function () {
    it('shows each size it reaches - its retail price, new price and difference - those an amount skips, and the products left out', function () {
        $category = Px::category('Kitchens');
        $reached = readsOn(['60 cm', '80 cm'], $category);
        $out = readsOn(['60 cm'], $category);
        Rx::set('sa', [$reached['variants'][0] => '100', $reached['variants'][1] => '15', $out['variants'][0] => '100']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $preview = app(PreviewCategoryDiscountHandler::class)->handle(new PreviewCategoryDiscount(Rx::storeId('sa'), $category, null, '20', 'BOTH', [$out['product']]));
        $sizes = $preview->reached[0]->sizes;

        expect($preview->reached)->toHaveCount(1)
            ->and($preview->reached[0]->productId)->toBe($reached['product'])
            ->and([$sizes[0]->retail->minor, $sizes[0]->newPrice?->minor, $sizes[0]->difference?->minor])->toBe([10000, 8000, 2000])
            // 20 off 15 would leave below 0: skipped, and why (Q9).
            ->and([$sizes[1]->retail->minor, $sizes[1]->newPrice, $sizes[1]->skipped])->toBe([1500, null, 'zero_or_below'])
            ->and([$preview->reachedProducts, $preview->reachedSizes, $preview->skippedSizes, $preview->deepestBasisPoints])->toBe([1, 1, 1, 2000])
            ->and(array_map(static fn ($product): string => $product->productId, $preview->leftOut))->toBe([$out['product']]);

        $saved = Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('sa'), $category, '10', null, 'BOTH', null, null)));
        $view = app(ViewCategoryDiscountHandler::class)->handle(new ViewCategoryDiscount($saved));

        // The note of what it overlaps (§1.3): a sale on a size it reaches, another discount on the same
        // products, in its dates - and never itself when it is being changed.
        Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$reached['variants'][0]], '5', null, null, null)));
        $note = app(PreviewCategoryDiscountHandler::class)->handle(new PreviewCategoryDiscount(Rx::storeId('sa'), $category, '5', null, 'BOTH', [], null, null, $saved));
        $later = app(PreviewCategoryDiscountHandler::class)->handle(new PreviewCategoryDiscount(Rx::storeId('sa'), $category, '5', null, 'BOTH', [], '2027-01-01 00:00:00+00', '2027-01-02 00:00:00+00'));

        expect($note->overlappingSales)->toHaveCount(1)
            ->and($note->overlappingDiscounts)->toBe([])
            ->and(app(PreviewCategoryDiscountHandler::class)->handle(new PreviewCategoryDiscount(Rx::storeId('sa'), $category, '5', null, 'BOTH'))->overlappingDiscounts)->toHaveCount(1)
            ->and($later->overlappingSales)->toHaveCount(1)
            ->and($view->discount->state)->toBe('RUNNING')
            ->and($view->preview->reached)->toHaveCount(2)
            ->and(app(ListCategoryDiscountsHandler::class)->handle(new ListCategoryDiscounts(Rx::storeId('sa')))->total)->toBe(1);
    });
});

describe('the lists, a size\'s prices and the note (§3)', function () {
    it('lists sales with their product and state, shows a size\'s prices, and names discounts in the note', function () {
        $category = Px::category('Kitchens');
        $ready = readsOn(['60 cm'], $category);
        [$size] = $ready['variants'];
        Rx::set('sa', [$size => '100']);
        Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$size], '10', null, null, null)));
        Fx::asSystem(fn () => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('sa'), $category, '5', null, 'RETAIL', null, null)));
        Cx::actAsStaffWith([PricingPermissions::CATEGORY_DISCOUNT_MANAGE], ['sa']);

        $sales = app(ListSalesHandler::class)->handle(new ListSales(Rx::storeId('sa')));
        $prices = app(ViewPricesHandler::class)->handle(new ViewPrices(Rx::storeId('sa'), $size));
        $note = app(OverlapsForHandler::class)->handle(new OverlapsFor(Rx::storeId('sa'), null, [$size], null, null));

        expect([$sales->total, $sales->sales[0]->state, $sales->sales[0]->productId])->toBe([1, 'RUNNING', $ready['product']])
            ->and($prices->retailMinor)->toBe(10000)
            ->and(array_values(array_unique(array_map(static fn (PriceLine $line): string => $line->kind, $prices->prices))))->toEqualCanonicalizing(['BASE', 'SALE', 'CATEGORY'])
            ->and($note->sales)->toHaveCount(1)
            ->and($note->discounts)->toHaveCount(1);
    });

    it('is refused without any of the three jobs there, and an off store to whoever may not switch stores', function () {
        $ready = readsOn(['60 cm']);
        $staff = Fx::staffWith([PricingPermissions::PRICE_EDIT], ['sa', 'eg']);
        $eg = Rx::storeId('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsStaff($staff);

        expect(fn () => app(ListSalesHandler::class)->handle(new ListSales($eg)))->toThrow(StoreOff::class)
            ->and(app(ListSalesHandler::class)->handle(new ListSales(Rx::storeId('sa')))->total)->toBe(0);

        // A job, but none of Pricing's.
        Cx::actAsStaffWith(['inventory.stock.manage'], ['sa']);

        expect(fn () => app(ViewPricesHandler::class)->handle(new ViewPrices(Rx::storeId('sa'), $ready['variants'][0])))->toThrow(Unauthorized::class);
    });
});
