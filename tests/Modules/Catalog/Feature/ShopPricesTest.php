<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The shop's prices (catalog.md amendment 35; the shop's screens' B8): a card's retail price for every
| viewer — now, before while a sale lowers it, "From" when its sizes differ — and "Wholesale from" for a
| company or a guest; each size's price on its page; and Pricing's bands there, `pricing.wholesale`,
| never for an individual. Pushed by Pricing as prices change, kept in the listing, written by the
| server in the store's currency.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function shopPricesViewer(ShopperKind $kind): void
{
    app()->forgetScopedInstances();
    app()->scoped(ShopperType::class, static fn (): ShopperType => new readonly class($kind) implements ShopperType
    {
        public function __construct(private ShopperKind $kind) {}

        public function current(): ShopperKind
        {
            return $this->kind;
        }
    });
}

/**
 * Three sizes in Saudi Arabia: 60 cm retail at 30.00, 80 cm both ways at 25.00 with a band from 5 at
 * 20.00, 100 cm wholesale only at 40.00 with bands from 5 at 35.00 and from 20 at 32.00.
 *
 * @return array{category: string, product: string, sizes: list<string>}
 */
function shopPricesProduct(): array
{
    $category = Px::category('Kitchens');
    $p = Px::ready(['60 cm', '80 cm', '100 cm'], $category);
    [$sixty, $eighty, $hundred] = $p['variants'];
    $store = Fx::storeId('sa');

    Fx::asSystem(function () use ($p, $store, $sixty, $eighty, $hundred): void {
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $p['product'], true));
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $p['product'], [
            $sixty => ['retail' => true, 'wholesale' => false],
            $eighty => ['retail' => true, 'wholesale' => true],
            $hundred => ['retail' => false, 'wholesale' => true],
        ], wholesaleMinimum: 5));
    });
    Rx::set('sa', [$sixty => '30.00', $eighty => '25.00', $hundred => '40.00']);
    Fx::asSystem(function () use ($store, $eighty, $hundred): void {
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $eighty, [5 => '20.00']));
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $hundred, [5 => '35.00', 20 => '32.00']));
    });

    return ['category' => $category, 'product' => $p['product'], 'sizes' => $p['variants']];
}

/**
 * The bands `pricing.wholesale` carries: size => [[from, minor]], or null when it draws nothing.
 *
 * @return array<string, list<array{int, int}>>|null
 */
function shopPricesBands(mixed $sections): ?array
{
    $wholesale = shopPricesSections($sections)['pricing.wholesale'] ?? null;

    if (! is_array($wholesale) || ! is_array($wholesale['sizes'] ?? null)) {
        return null;
    }

    $sizes = [];

    foreach ($wholesale['sizes'] as $variantId => $bands) {
        foreach (is_array($bands) ? $bands : [] as $band) {
            $unit = is_array($band) && is_array($band['unit'] ?? null) ? $band['unit'] : [];
            $sizes[(string) $variantId][] = [is_array($band) && is_int($band['minQuantity'] ?? null) ? $band['minQuantity'] : 0, is_int($unit['minor'] ?? null) ? $unit['minor'] : 0];
        }
    }

    return $sizes;
}

/**
 * The page's sections, as the assertion hands them (a collection, or an array).
 *
 * @return array<array-key, mixed>
 */
function shopPricesSections(mixed $sections): array
{
    return $sections instanceof Collection ? $sections->all() : (is_array($sections) ? $sections : []);
}

function shopPricesSlug(string $kind, string $id): string
{
    return (string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', 'en')->where('is_current', true)->value('slug');
}

describe('a card', function () {
    it('shows every viewer retail and "From", and a company or a guest "Wholesale from"', function () {
        $p = shopPricesProduct();
        $url = '/sa/en/categories/'.shopPricesSlug('category', $p['category']);
        $card = static fn (?int $wholesaleFrom) => static fn (AssertableInertia $page) => $page
            ->where('products.0.price.now.minor', 30_00)
            ->where('products.0.price.now.text', fn (string $text): bool => str_starts_with($text, '30.00 '))
            ->where('products.0.price.before', null)
            ->where('products.0.price.from.minor', 25_00)
            ->where('products.0.price.wholesaleFrom.minor', $wholesaleFrom);

        // A guest: the least a piece costs on a wholesale line of any wholesale size — 80 cm's band from 5.
        get($url)->assertOk()->assertInertia($card(20_00));
        get('/sa/en/search?q=Drawer')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.0.price.now.minor', 30_00)->where('products.0.price.wholesaleFrom.minor', 20_00));

        shopPricesViewer(ShopperKind::Company);
        get($url)->assertInertia($card(20_00));

        shopPricesViewer(ShopperKind::Individual);
        get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.price.wholesaleFrom', null)->where('products.0.price.from.minor', 25_00));
    });

    it('follows a sale at once: the price before beside it, and a lower "Wholesale from" when it goes under a band', function () {
        $p = shopPricesProduct();
        [$sixty, $eighty] = $p['sizes'];
        $url = '/sa/en/categories/'.shopPricesSlug('category', $p['category']);
        get($url)->assertOk();

        // Within the store's discount ceiling (30%): 60 cm at 24.00, 80 cm at 18.00 — under its band of 20.00.
        // On a whole second: a sale starting "now" starts at the next one (Pricing's SaleInput).
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
        Fx::asSystem(function () use ($sixty, $eighty): void {
            app(AddSaleHandler::class)->handle(new AddSale(Fx::storeId('sa'), null, [$sixty], null, '24.00', null, null));
            app(AddSaleHandler::class)->handle(new AddSale(Fx::storeId('sa'), null, [$eighty], null, '18.00', null, null));
        });

        get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.0.price.now.minor', 24_00)
            ->where('products.0.price.before.minor', 30_00)
            ->where('products.0.price.from.minor', 18_00)
            ->where('products.0.price.wholesaleFrom.minor', 18_00));
    });

    it('has no "From" while its sizes cost the same, and no price at all before it is priced', function () {
        $category = Px::category('Kitchens');
        $p = Px::ready(['60 cm', '80 cm'], $category);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        $url = '/sa/en/categories/'.shopPricesSlug('category', $category);

        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.price', null));

        Rx::set('sa', [$p['variants'][0] => '30.00', $p['variants'][1] => '30.00']);

        get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.price.now.minor', 30_00)->where('products.0.price.from', null)->where('products.0.price.wholesaleFrom', null));
    });
});

describe('a product page', function () {
    it('shows each size its price, and the wholesale bands to a company or a guest, never to an individual', function () {
        $p = shopPricesProduct();
        [, $eighty, $hundred] = $p['sizes'];
        $url = '/sa/en/products/'.shopPricesSlug('product', $p['product']);

        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('variants.0.price.minor', 30_00)
            ->where('variants.0.priceBefore', null)
            ->where('variants.1.price.minor', 25_00)
            ->where('variants.2.price.minor', 40_00)
            // 60 cm sells retail only: no bands.
            ->where('sections', fn ($sections): bool => shopPricesBands($sections) === [$eighty => [[5, 20_00]], $hundred => [[5, 35_00], [20, 32_00]]]));

        shopPricesViewer(ShopperKind::Company);
        get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('sections', fn ($sections): bool => shopPricesBands($sections) !== null));

        shopPricesViewer(ShopperKind::Individual);
        get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('variants', 2)
            ->where('sections', fn ($sections): bool => array_key_exists('pricing.wholesale', shopPricesSections($sections)) && shopPricesBands($sections) === null));
    });

    it('reads the bands in one query, warm, and none for an individual or a product sold retail only', function () {
        $p = shopPricesProduct();
        $url = '/sa/en/products/'.shopPricesSlug('product', $p['product']);
        $warm = static function () use ($url): int {
            app()->forgetScopedInstances();
            get($url)->assertOk();
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            get($url)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $guest = $warm();
        shopPricesViewer(ShopperKind::Individual);

        // A guest's product page is 7 sold retail only (ShopPagesTest's budget); the bands add their one
        // read of the sizes' prices. The limits are a target, not a cap (owner, 2026-10-19).
        expect([$guest, $warm()])->toBe([8, 7]);
    });
});
