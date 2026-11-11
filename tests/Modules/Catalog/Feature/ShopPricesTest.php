<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiences;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiencesHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Modules\Pricing\Presentation\Console\RebuildPriceCandidatesCommand;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
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
    Px::onSale('sa', $p['product']);
    Rx::set('sa', [$sixty => '30.00', $eighty => '25.00', $hundred => '40.00']);
    Fx::asSystem(function () use ($store, $eighty, $hundred): void {
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $eighty, [5 => '20.00']));
        app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $hundred, [5 => '35.00', 20 => '32.00']));
    });

    return ['category' => $category, 'product' => $p['product'], 'sizes' => $p['variants']];
}

/**
 * What `pricing.wholesale` carries: size => [its wholesale price, [[from, minor]]], or null when it draws nothing.
 *
 * @return array<string, array{int, list<array{int, int}>}>|null
 */
function shopPricesBands(mixed $sections): ?array
{
    $wholesale = shopPricesSections($sections)['pricing.wholesale'] ?? null;

    if (! is_array($wholesale) || ! is_array($wholesale['sizes'] ?? null)) {
        return null;
    }

    $minor = static fn (mixed $money): int => is_array($money) && is_int($money['minor'] ?? null) ? $money['minor'] : 0;
    $sizes = [];

    foreach ($wholesale['sizes'] as $variantId => $size) {
        $bands = [];

        foreach (is_array($size) && is_array($size['bands'] ?? null) ? $size['bands'] : [] as $band) {
            $bands[] = [is_array($band) && is_int($band['minQuantity'] ?? null) ? $band['minQuantity'] : 0, $minor(is_array($band) ? ($band['unit'] ?? null) : null)];
        }

        $sizes[(string) $variantId] = [$minor(is_array($size) ? ($size['price'] ?? null) : null), $bands];
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
            ->where($wholesaleFrom === null ? 'products.0.price.wholesaleFrom' : 'products.0.price.wholesaleFrom.minor', $wholesaleFrom)
            // A band is a wholesale line's normal price, not a reduction: nothing struck (amendment 45).
            ->where('products.0.price.wholesaleFromBefore', null);

        // A guest: the least a piece costs on a wholesale line of any wholesale size — 80 cm's band from 5.
        get($url)->assertOk()->assertInertia($card(20_00));
        get('/sa/en/search?q=Drawer')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.0.price.now.minor', 30_00)->where('products.0.price.wholesaleFrom.minor', 20_00));

        shopPricesViewer(ShopperKind::Company);
        get($url)->assertInertia($card(20_00));

        shopPricesViewer(ShopperKind::Individual);
        get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.price.wholesaleFrom', null)->where('products.0.price.from.minor', 25_00));

        // A band changed: "Wholesale from" follows at once.
        shopPricesViewer(ShopperKind::Guest);
        Fx::asSystem(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices(Fx::storeId('sa'), $p['sizes'][1], [5 => '19.00'])));
        get($url)->assertInertia($card(19_00));

        // Its category closed to companies: no company can buy it wholesale, so a guest is not shown it.
        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $p['category'], forCompanies: false)));
        get($url)->assertInertia($card(null));
    });

    it('shows a guest the retail size\'s price when the first size is sold wholesale only', function () {
        $category = Px::category('Kitchens');
        $p = Px::ready(['100 cm', '60 cm'], $category);
        [$wholesale, $retail] = $p['variants'];
        Fx::asSystem(function () use ($p, $wholesale, $retail): void {
            app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true));
            app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $p['product'], [
                $wholesale => ['retail' => false, 'wholesale' => true],
                $retail => ['retail' => true, 'wholesale' => false],
            ], wholesaleMinimum: 5));
        });
        Px::onSale('sa', $p['product']);
        Rx::set('sa', [$wholesale => '40.00', $retail => '30.00']);

        // 100 cm's own price, 40.00, is not retail: the card shows 60 cm's, and "Wholesale from" 40.00 (no bands).
        get('/sa/en/categories/'.shopPricesSlug('category', $category))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.0.price.now.minor', 30_00)
            ->where('products.0.price.from', null)
            ->where('products.0.price.wholesaleFrom.minor', 40_00));
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

        // "Wholesale from" 18.00 is the sale under 80 cm's band of 20.00: that band struck through (amendment 45).
        get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.0.price.now.minor', 24_00)
            ->where('products.0.price.before.minor', 30_00)
            ->where('products.0.price.from.minor', 18_00)
            ->where('products.0.price.wholesaleFrom.minor', 18_00)
            ->where('products.0.price.wholesaleFromBefore.minor', 20_00));
    });

    // Amendment 45 (owner, 2026-11-11): a wholesale-only sale shows its old price struck through.
    it('strikes "Wholesale from" through only while an offer takes the card\'s least wholesale price lower', function () {
        $p = shopPricesProduct();
        [, $eighty, $hundred] = $p['sizes'];
        $url = '/sa/en/categories/'.shopPricesSlug('category', $p['category']);
        $card = static fn (int $wholesaleFrom, ?int $before) => static fn (AssertableInertia $page) => $page
            ->where('products.0.price.wholesaleFrom.minor', $wholesaleFrom)
            ->where($before === null ? 'products.0.price.wholesaleFromBefore' : 'products.0.price.wholesaleFromBefore.minor', $before);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());

        // 100 cm, sold wholesale only, on sale at 30.00 - under its bands of 35.00 and 32.00 - while 80 cm's band
        // of 20.00 stays the least: the card's "Wholesale from" is not lower than it would be, so nothing is struck.
        Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Fx::storeId('sa'), null, [$hundred], null, '30.00', null, null)));
        get($url)->assertOk()->assertInertia($card(20_00, null));
        expect(DB::table('catalog.store_variant_facts')->where('variant_id', $hundred)->first(['wholesale_from_minor', 'wholesale_from_before_minor']))
            ->toEqual((object) ['wholesale_from_minor' => 30_00, 'wholesale_from_before_minor' => 32_00]);

        // 80 cm at 40.00, its band from 5 at 31.00: 100 cm's sale is now the least. Struck through is what the card
        // would say with no offer - 80 cm's 31.00, the least normal price - not 100 cm's own 32.00 (lowest price wins).
        Rx::set('sa', [$eighty => '40.00']);
        Fx::asSystem(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices(Fx::storeId('sa'), $eighty, [5 => '31.00'])));
        get($url)->assertInertia($card(30_00, 31_00));
        // Search reads its own columns: the same card there.
        get('/sa/en/search?q=Drawer')->assertOk()->assertInertia($card(30_00, 31_00));

        // A company sees what a guest sees; an individual never a wholesale price, nor its old one.
        shopPricesViewer(ShopperKind::Company);
        get($url)->assertInertia($card(30_00, 31_00));
        shopPricesViewer(ShopperKind::Individual);
        get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.price.wholesaleFrom', null)->where('products.0.price.wholesaleFromBefore', null));
    });

    it('strikes a wholesale-only product\'s "Wholesale from" through on a sale, and not once a band goes under the sale', function () {
        $category = Px::category('Kitchens');
        $p = Px::ready(['100 cm'], $category);
        [$hundred] = $p['variants'];
        $store = Fx::storeId('sa');
        Fx::asSystem(function () use ($p, $store, $hundred): void {
            app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $p['product'], true));
            app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $p['product'], [$hundred => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5));
        });
        Px::onSale('sa', $p['product']);
        Rx::set('sa', [$hundred => '40.00']);
        Fx::asSystem(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $hundred, [5 => '35.00', 20 => '32.00'])));
        $url = '/sa/en/categories/'.shopPricesSlug('category', $category);
        $card = static fn (int $wholesaleFrom, ?int $before) => static fn (AssertableInertia $page) => $page
            ->where('products.0.price.now', null)
            ->where('products.0.price.wholesaleFrom.minor', $wholesaleFrom)
            ->where($before === null ? 'products.0.price.wholesaleFromBefore' : 'products.0.price.wholesaleFromBefore.minor', $before);

        get($url)->assertOk()->assertInertia($card(32_00, null));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
        Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale($store, null, [$hundred], null, '30.00', null, null)));
        get($url)->assertInertia($card(30_00, 32_00));
        shopPricesViewer(ShopperKind::Company);
        get($url)->assertInertia($card(30_00, 32_00));

        // Deploying (HANDOFF §15): the columns start empty; Pricing's repair pushes every price, which fills them.
        DB::table('catalog.store_variant_facts')->where('variant_id', $hundred)->update(['wholesale_from_before_minor' => null]);
        DB::table('catalog.listing')->where('product_id', $p['product'])->update(['wholesale_from_before_minor' => null]);
        get($url)->assertInertia($card(30_00, null));
        expect(Artisan::call(RebuildPriceCandidatesCommand::NAME))->toBe(0);
        get($url)->assertInertia($card(30_00, 32_00));

        // The band from 20 at 29.00, under the sale: the least is a band, a normal price, so nothing is struck.
        shopPricesViewer(ShopperKind::Guest);
        Fx::asSystem(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $hundred, [5 => '35.00', 20 => '29.00'])));
        get($url)->assertInertia($card(29_00, null));
    });

    it('has no "From" while its sizes cost the same; before it is priced it is not listed at all (amendment 39)', function () {
        $category = Px::category('Kitchens');
        $p = Px::ready(['60 cm', '80 cm'], $category);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        Ix::count('sa', array_fill_keys($p['variants'], 5));
        $url = '/sa/en/categories/'.shopPricesSlug('category', $category);

        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('products', [])->where('soldOut', true));

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
            // 100 cm sells wholesale only: its price is Pricing's section's, not a retail one.
            ->where('variants.2.price', null)
            // 60 cm sells retail only: not in it.
            ->where('sections', fn ($sections): bool => shopPricesBands($sections) === [$eighty => [25_00, [[5, 20_00]]], $hundred => [40_00, [[5, 35_00], [20, 32_00]]]]));

        shopPricesViewer(ShopperKind::Company);
        get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('sections', fn ($sections): bool => shopPricesBands($sections) !== null));

        shopPricesViewer(ShopperKind::Individual);
        get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->has('variants', 2)
            ->where('sections', fn ($sections): bool => array_key_exists('pricing.wholesale', shopPricesSections($sections)) && shopPricesBands($sections) === null));
    });

    // Amendment 45 (owner, 2026-11-11): a wholesale-only size on sale shows its old price struck through.
    it('gives each wholesale price its normal price while an offer takes it lower, and none for a band alone', function () {
        $p = shopPricesProduct();
        [, $eighty, $hundred] = $p['sizes'];
        $url = '/sa/en/products/'.shopPricesSlug('product', $p['product']);
        $minor = static fn (mixed $money): ?int => is_array($money) && is_int($money['minor'] ?? null) ? $money['minor'] : null;
        // size => [its price before, [[from, its unit before]]]
        $befores = static function (mixed $sections) use ($minor): array {
            $sizes = [];

            foreach ((array) (shopPricesSections($sections)['pricing.wholesale']['sizes'] ?? []) as $variantId => $size) {
                $sizes[(string) $variantId] = [$minor($size['priceBefore'] ?? null), array_map(static fn (array $band): array => [$band['minQuantity'], $minor($band['unitBefore'] ?? null)], $size['bands'] ?? [])];
            }

            return $sizes;
        };

        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sections', fn ($sections): bool => $befores($sections) === [$eighty => [null, [[5, null]]], $hundred => [null, [[5, null], [20, null]]]]));

        // 100 cm, sold wholesale only, on sale at 30.00: under its own price of 40.00 and both its bands.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
        Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Fx::storeId('sa'), null, [$hundred], null, '30.00', null, null)));

        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sections', fn ($sections): bool => shopPricesBands($sections) === [$eighty => [25_00, [[5, 20_00]]], $hundred => [30_00, [[5, 30_00], [20, 30_00]]]]
                && $befores($sections) === [$eighty => [null, [[5, null]]], $hundred => [40_00, [[5, 35_00], [20, 32_00]]]]));
    });

    // Security audit run-2 (2026-10-27): the page showed a guest the bands where its card did not.
    it('shows a guest the bands only where companies may buy the product, as its card does', function () {
        $p = shopPricesProduct();
        [, $eighty, $hundred] = $p['sizes'];
        $url = '/sa/en/products/'.shopPricesSlug('product', $p['product']);
        $audience = static fn (bool $forCompanies) => Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)
            ->handle(new SetCategoryAudiences(Fx::storeId('sa'), $p['category'], forCompanies: $forCompanies)));

        // Its category closed to companies: no company can buy it wholesale, so a guest sees no bands.
        $audience(false);
        $closed = fn (AssertableInertia $page) => $page
            ->where('variants.0.price.minor', 30_00)
            ->where('sections', fn ($sections): bool => shopPricesBands($sections) === null);
        get($url)->assertOk()->assertInertia($closed);
        // The page as kept says the same.
        get($url)->assertOk()->assertInertia($closed);

        // Open again: the bands come back - the kept page is a new version after the change.
        $audience(true);
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sections', fn ($sections): bool => shopPricesBands($sections) === [$eighty => [25_00, [[5, 20_00]]], $hundred => [40_00, [[5, 35_00], [20, 32_00]]]]));
    });

    // A product sold retail only reads none: ShopPagesTest's budgets, unchanged by amendment 35.
    it('reads the wholesale prices in one query, warm, and none for an individual', function () {
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

        // A guest's product page is 14 sold retail only (ShopPagesTest's budget, with Sales's Add to Cart
        // since amendment 36); the bands add their one read of the sizes' prices; an individual's has no
        // bands and no question of whether companies may buy it. The limits are a target, not a cap
        // (owner, 2026-10-19).
        expect([$guest, $warm()])->toBe([15, 13]);
    });
});
