<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Application\Products\InMemoryShopProductSections;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Sales\Presentation\Storefront\CartSection;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| Add to Cart on a product's page (sales.md §4.2 B15), `sales.cart`: for each size the page shows, the limits
| of each mode this viewer may buy it in, whether too few are free, and the pieces already in their cart.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * 60 cm retail only, 80 cm both ways, 100 cm wholesale only; wholesale from 5, retail at most 8; 10, 3 and 4 in stock.
 *
 * @return array{product: string, sizes: list<string>}
 */
function shopCartProduct(): array
{
    $p = Px::ready(['60 cm', '80 cm', '100 cm']);
    [$sixty, $eighty, $hundred] = $p['variants'];
    $store = Fx::storeId('sa');

    Fx::asSystem(function () use ($p, $store, $sixty, $eighty, $hundred): void {
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $p['product'], true));
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $p['product'], [
            $sixty => ['retail' => true, 'wholesale' => false],
            $eighty => ['retail' => true, 'wholesale' => true],
            $hundred => ['retail' => false, 'wholesale' => true],
        ], retailMaximum: 8, wholesaleMinimum: 5));
    });
    Rx::set('sa', [$sixty => '30.00', $eighty => '25.00', $hundred => '40.00']);
    Ix::count('sa', [$sixty => 10, $eighty => 3, $hundred => 4]);

    return ['product' => $p['product'], 'sizes' => $p['variants']];
}

/**
 * Sales's part of the page as the screens read it: size => [retail limits, wholesale limits, out of stock, in cart].
 *
 * @param  list<string>  $sizes
 * @return array<string, array{?array{int, ?int}, ?array{int, ?int}, bool, int}>|null
 */
function shopCartRead(string $product, array $sizes, ShopperKind $viewer, ?string $customerId = null, bool $available = true): ?array
{
    $parts = app(InMemoryShopProductSections::class)->read(StoreId::fromString(Fx::storeId('sa')), 'en', $product, $customerId, [], [], $available, $viewer, [], $sizes);

    if (! array_key_exists('sales.cart', $parts)) {
        return null;
    }

    $read = json_decode((string) json_encode($parts['sales.cart']), true);
    $limits = static fn (mixed $mode): ?array => is_array($mode) ? [$mode['minimum'], $mode['maximum']] : null;
    $sizesRead = [];

    foreach (is_array($read) ? $read['sizes'] : [] as $size) {
        $sizesRead[$size['variantId']] = [$limits($size['retail']), $limits($size['wholesale']), $size['outOfStock'], $size['inCart']];
    }

    return $sizesRead;
}

it('offers a guest and a company each mode a size is sold in, an individual retail only', function () {
    ['product' => $product, 'sizes' => [$sixty, $eighty, $hundred]] = shopCartProduct();
    $both = [
        $sixty => [[1, 8], null, false, 0],
        $eighty => [[1, 8], [5, null], false, 0],
        // 4 free, a wholesale line holds 5 at least.
        $hundred => [null, [5, null], true, 0],
    ];

    expect(shopCartRead($product, [$sixty, $eighty, $hundred], ShopperKind::Guest))->toBe($both)
        // An unapproved company too (owner, 2026-10-27): the cart marks its wholesale lines.
        ->and(shopCartRead($product, [$sixty, $eighty, $hundred], ShopperKind::Company))->toBe($both)
        ->and(shopCartRead($product, [$sixty, $eighty, $hundred], ShopperKind::Individual))->toBe([
            $sixty => [[1, 8], null, false, 0],
            $eighty => [[1, 8], null, false, 0],
            $hundred => [null, null, false, 0],
        ]);
});

it('counts the pieces already in the cart, against the stock too', function () {
    ['product' => $product, 'sizes' => [$sixty, $eighty]] = shopCartProduct();
    $customer = Cx::customer();
    Fx::actAsCustomer($customer);
    Cx::add($sixty, 8);
    Cx::add($eighty, 2);

    // 60 cm: 8 of 10 in the cart, 1 more fits; 80 cm: 2 of 3, 1 more fits retail.
    expect(shopCartRead($product, [$sixty, $eighty], ShopperKind::Individual, $customer))->toBe([
        $sixty => [[1, 8], null, false, 8],
        $eighty => [[1, 8], null, false, 2],
    ]);

    Cx::add($eighty, 1);

    expect(shopCartRead($product, [$eighty], ShopperKind::Individual, $customer))->toBe([$eighty => [[1, 8], null, true, 3]]);
});

it('offers nothing for a size not sold here now, and is left off a "Not available now" page and a page of no sizes', function () {
    ['product' => $product, 'sizes' => [$sixty, $eighty]] = shopCartProduct();
    DB::table('catalog.store_variants')->where('variant_id', $sixty)->update(['not_available_now' => true]);

    expect(shopCartRead($product, [$sixty, $eighty], ShopperKind::Guest))->toBe([
        $sixty => [null, null, false, 0],
        $eighty => [[1, 8], [5, null], false, 0],
    ])
        ->and(shopCartRead($product, [$sixty], ShopperKind::Guest, available: false))->toBeNull()
        ->and(app(InMemoryShopProductSections::class)->read(StoreId::fromString(Fx::storeId('sa')), 'en', $product, null, [], [], true, ShopperKind::Guest, [], [])['sales.cart'])->toBeNull();
});

it('is on the product page for the sizes the page shows, in its order', function () {
    ['product' => $product, 'sizes' => $sizes] = shopCartProduct();
    $slug = (string) DB::table('catalog.product_slugs')->where('product_id', $product)->where('locale', 'en')->where('is_current', true)->value('slug');

    get('/sa/en/products/'.$slug)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('variants', fn ($variants): bool => array_column(json_decode((string) json_encode($variants), true), 'id') === $sizes)
        ->where('sections', fn ($sections): bool => array_column(json_decode((string) json_encode($sections), true)['sales.cart']['sizes'] ?? [], 'variantId') === $sizes));
});

it('reads the sizes, their stock and the cart in the same queries, whatever the number of sizes', function () {
    ['product' => $product, 'sizes' => $sizes] = shopCartProduct();
    $customer = Cx::customer();
    Fx::actAsCustomer($customer);
    Cx::add($sizes[0]);
    $store = StoreId::fromString(Fx::storeId('sa'));
    $count = function (string ...$sizes) use ($product, $customer, $store): int {
        $section = app(CartSection::class);
        $read = static fn () => $section->read(new ShopProductVisitDto($store, 'en', $product, $customer, [], [], ShopperKind::Individual, [], array_values($sizes)));
        $read();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // Measured 2026-10-27: Catalog's sizes in the store (its own reads), Inventory's stock, the cart's pieces —
    // the same for one size as for three. A target, not a cap (owner, 2026-10-19).
    expect([$count($sizes[0]), $count(...$sizes)])->toBe([5, 5]);
});
