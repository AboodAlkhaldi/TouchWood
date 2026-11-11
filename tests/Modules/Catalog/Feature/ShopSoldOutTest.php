<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiences;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiencesHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Public\Contracts\ShopBestSellers;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Sales\Application\Query\ViewCart\CartLineView;
use Modules\Sales\Application\Query\ViewCart\ViewCart;
use Modules\Sales\Application\Query\ViewCart\ViewCartHandler;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Public\Enums\CartLineProblem;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Sx;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

/*
| Out of stock is never shown (handoff §9.2; catalog.md §1.3, §1.4; amendment 39), through Inventory's
| real flow: a size sold out by an order's hold, or by hand, leaves every list, its card and each side's
| prices; a product with no size left has the "Not available now" page; a size sold out beside others
| stays on the page greyed, and is never offered. Back in stock, it all comes back. A wired ordinary
| product at 0 stays orderable (inventory.md §1.3).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function shopSoldOutViewer(ShopperKind $kind): void
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
 * A ready product in this category, on sale in Saudi Arabia: each size counted at 5 and priced.
 *
 * @param  list<string>  $sizes
 * @param  list<string>  $prices  each size's price, in order
 * @return array{product: string, variants: list<string>, width: string}
 */
function shopSoldOutProduct(string $categoryId, array $sizes = ['60 cm'], array $prices = []): array
{
    $p = Px::ready($sizes, $categoryId);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
    Ix::count('sa', array_fill_keys($p['variants'], 5));
    Rx::set('sa', array_combine($p['variants'], $prices === [] ? array_fill(0, count($sizes), '10.00') : $prices));

    return $p;
}

/** The size sold out as a shop sells it: an order holds every piece left. */
function shopSoldOutByOrder(string $variantId, int $pieces = 5): string
{
    $order = strtolower((string) Str::ulid());
    app(InventoryApi::class)->hold(StoreId::fromString(Ix::storeId('sa')), $order, [new HoldLineDto($variantId, $pieces)], null);

    return $order;
}

/** A wired store's stock as Sync writes it: the provider's number, read in. */
function shopSoldOutProviderSays(string $variantId, int $pieces, string $readRef): void
{
    Fx::asSystem(fn () => app(ProviderStock::class)->apply(StoreId::fromString(Ix::storeId('sa')), [$variantId => $pieces], $readRef));
}

function shopSoldOutSlug(string $kind, string $id, string $locale = 'en'): string
{
    return (string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', $locale)->where('is_current', true)->value('slug');
}

function shopSoldOutBrandOf(string $productId): string
{
    return (string) DB::table('catalog.products')->where('id', $productId)->value('brand_id');
}

/**
 * @return array<string, mixed> the Inertia page's props, as the page receives them
 */
function shopSoldOutProps(string $url): array
{
    app()->forgetScopedInstances();
    /** @var TestResponse<Response> $response */
    $response = get($url)->assertOk();
    $props = [];
    $response->assertInertia(function (AssertableInertia $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * @return array<string, mixed> a size's card as this list has it
 */
function shopSoldOutCard(string $url, string $productId): array
{
    foreach (shopSoldOutProps($url)['products'] as $card) {
        if ($card['productId'] === $productId) {
            return $card;
        }
    }

    throw new LogicException("No card of {$productId} on {$url}.");
}

/**
 * @return list<string> the product ids a list holds
 */
function shopSoldOutIds(string $url): array
{
    return array_column(shopSoldOutProps($url)['products'], 'productId');
}

/**
 * @return list<string> the product ids the suggestions hold
 */
function shopSoldOutSuggested(string $typed): array
{
    app()->forgetScopedInstances();

    return array_column(getJson('/sa/en/search/suggest?q='.$typed)->assertOk()->json('products'), 'productId');
}

/** How many queries a page asks, warm, as production serves it: once to fill the caches, then counted as a new request. */
function shopSoldOutQueries(AdminBrowser $browser, string $url): int
{
    $browser->get($url)->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $browser->get($url)->assertOk();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

describe('a product with no size left', function () {
    it('leaves every list and has the "Not available now" page, for each viewer', function (ShopperKind $viewer) {
        $category = Px::category('Kitchens');
        $gone = shopSoldOutProduct($category, ['60 cm']);
        $stays = shopSoldOutProduct($category, ['80 cm']);
        $categoryUrl = '/sa/en/categories/'.shopSoldOutSlug('category', $category);
        $brandUrl = '/sa/en/brands/'.shopSoldOutSlug('brand', shopSoldOutBrandOf($gone['product']));
        $productUrl = '/sa/en/products/'.shopSoldOutSlug('product', $gone['product']);
        $goneName = (string) DB::table('catalog.products')->where('id', $gone['product'])->value('name_en');
        shopSoldOutViewer($viewer);

        // On sale: on every list, with its filter, as the shop has always shown it.
        expect(shopSoldOutIds($categoryUrl))->toContain($gone['product'], $stays['product'])
            ->and(shopSoldOutIds($brandUrl))->toContain($gone['product'])
            ->and(shopSoldOutIds('/sa/en/search?q='.rawurlencode($goneName)))->toContain($gone['product'])
            ->and(shopSoldOutSuggested(rawurlencode($goneName)))->toContain($gone['product'])
            ->and(shopSoldOutProps($productUrl)['available'])->toBeTrue();

        shopSoldOutByOrder($gone['variants'][0]);
        shopSoldOutViewer($viewer);

        $product = shopSoldOutProps($productUrl);
        $list = shopSoldOutProps($categoryUrl);

        expect(array_column($list['products'], 'productId'))->toBe([$stays['product']])
            // Its size's value is no longer a filter here.
            ->and(array_column($list['filters']['attributes'][0]['choices'] ?? [], 'name'))->not->toContain('60 cm')
            ->and(array_column($list['filters']['brands'], 'id'))->not->toContain(shopSoldOutBrandOf($gone['product']))
            ->and(shopSoldOutIds($brandUrl))->toBe([])
            ->and(shopSoldOutIds('/sa/en/search?q='.rawurlencode($goneName)))->not->toContain($gone['product'])
            ->and(shopSoldOutSuggested(rawurlencode($goneName)))->not->toContain($gone['product'])
            // A direct link: the one page, nothing to choose, nothing to buy; only sold out, it stays listed (owner, 2026-11-03).
            ->and($product['available'])->toBeFalse()
            ->and($product['address']['noindex'])->toBeFalse()
            ->and($product['variants'])->toBe([])
            ->and($product['choices'])->toBe([])
            ->and($product['sections'])->not->toHaveKey('sales.cart');
    })->with([
        'a guest' => [ShopperKind::Guest],
        'an individual' => [ShopperKind::Individual],
        'a company' => [ShopperKind::Company],
    ]);

    it('leaves the suggestions on a product page, the store\'s best sellers and the menu', function () {
        $alone = Px::category('Hinges');
        $soleOne = shopSoldOutProduct($alone, ['60 cm']);
        $kitchens = Px::category('Kitchens');
        $page = shopSoldOutProduct($kitchens, ['60 cm']);
        $beside = shopSoldOutProduct($kitchens, ['80 cm']);
        $productUrl = '/sa/en/products/'.shopSoldOutSlug('product', $page['product']);
        $best = static function (): array {
            app()->forgetScopedInstances();

            return array_column(app(ShopBestSellers::class)->read(StoreId::fromString(Fx::storeId('sa')), 'en', 20)['cards'], 'productId');
        };

        expect(array_column(shopSoldOutProps($productUrl)['mayAlsoLike'], 'productId'))->toContain($beside['product'])
            ->and($best())->toContain($soleOne['product'], $beside['product'])
            ->and(array_column(shopSoldOutProps($productUrl)['frame']['catalog']['menu'], 'id'))->toContain($alone);

        shopSoldOutByOrder($beside['variants'][0]);
        shopSoldOutByOrder($soleOne['variants'][0]);

        expect(array_column(shopSoldOutProps($productUrl)['mayAlsoLike'], 'productId'))->not->toContain($beside['product'])
            ->and($best())->not->toContain($soleOne['product'])
            ->and($best())->not->toContain($beside['product'])
            // A category with nothing to order has no place in the menu, and no page.
            ->and(array_column(shopSoldOutProps($productUrl)['frame']['catalog']['menu'], 'id'))->not->toContain($alone);

        // It keeps its page, with its sentence, its own address and in search engines (owner, 2026-11-03).
        $emptied = shopSoldOutProps('/sa/en/categories/'.shopSoldOutSlug('category', $alone));

        expect($emptied['products'])->toBe([])
            ->and($emptied['soldOut'])->toBeTrue()
            ->and($emptied['address']['noindex'])->toBeFalse()
            ->and($emptied['address']['canonical'])->toBe(url('/sa/en/categories/'.shopSoldOutSlug('category', $alone)));
    });

    it('leaves through a removal by hand and a shipment as through an order\'s hold', function () {
        $category = Px::category('Kitchens');
        $byHand = shopSoldOutProduct($category, ['60 cm']);
        $shipped = shopSoldOutProduct($category, ['80 cm']);
        $url = '/sa/en/categories/'.shopSoldOutSlug('category', $category);
        $stays = shopSoldOutProduct($category, ['100 cm']);

        Ix::adjust('sa', $byHand['variants'][0], 'REMOVE', 5, 'DAMAGED_LOST');
        $order = shopSoldOutByOrder($shipped['variants'][0]);
        app(InventoryApi::class)->ship($order, [new HoldLineDto($shipped['variants'][0], 5)]);

        expect(shopSoldOutIds($url))->toBe([$stays['product']]);
    });

    it('keeps the row, in place and honest, so a size coming back asks no new lock on its photo', function () {
        $p = shopSoldOutProduct(Px::category('Kitchens'), ['60 cm']);
        // A row deleted and written again would refuse here: only a change in place gets through.
        DB::statement("CREATE FUNCTION catalog.sold_out_test_refuse() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'A listing row was deleted.'; END $$");
        DB::statement('CREATE TRIGGER sold_out_test_refuse BEFORE DELETE ON catalog.listing FOR EACH ROW EXECUTE FUNCTION catalog.sold_out_test_refuse()');
        $row = static fn (): stdClass => DB::table('catalog.listing')->where('store_id', Fx::storeId('sa'))->where('locale', 'en')->where('product_id', $p['product'])->first() ?? throw new LogicException('No row.');
        $before = $row();

        expect($before->orderable)->toBeTrue();

        $order = shopSoldOutByOrder($p['variants'][0]);

        expect($row()->orderable)->toBeFalse()
            ->and($row()->card_media_id)->toBe($before->card_media_id)
            ->and($row()->slug)->toBe($before->slug)
            ->and($row()->sells_retail)->toBeFalse()
            ->and($row()->for_individuals)->toBeFalse()
            ->and($row()->retail_card_variant_id)->toBeNull()
            ->and($row()->price_minor)->toBeNull()
            ->and($row()->retail_price_minor)->toBeNull();

        // The refusal is real: a delete here would have failed this test.
        expect(fn () => DB::transaction(fn () => DB::table('catalog.listing')->where('product_id', $p['product'])->delete()))->toThrow(QueryException::class, 'A listing row was deleted.');

        // The repair writes the same.
        DB::transaction(fn () => app(ListingRows::class)->rebuild());
        expect($row()->orderable)->toBeFalse();

        app(InventoryApi::class)->release($order);

        expect($row()->orderable)->toBeTrue()
            ->and($row()->sells_retail)->toBeTrue()
            ->and($row()->price_minor)->toBe($before->price_minor);
    });
});

describe('a product with a size left', function () {
    it('moves the card to the next orderable size, with its price, and leaves it out of "From" and the filters', function () {
        $category = Px::category('Kitchens');
        $p = shopSoldOutProduct($category, ['60 cm', '80 cm', '100 cm'], ['30.00', '25.00', '40.00']);
        [$sixty, $eighty] = $p['variants'];
        $url = '/sa/en/categories/'.shopSoldOutSlug('category', $category);
        $listing = static fn (): stdClass => DB::table('catalog.listing')->where('store_id', Fx::storeId('sa'))->where('locale', 'en')->where('product_id', $p['product'])->first() ?? throw new LogicException('No row.');

        shopSoldOutViewer(ShopperKind::Individual);
        $card = shopSoldOutCard($url, $p['product']);
        expect($card['price']['now']['minor'])->toBe(30_00)->and($card['price']['from']['minor'])->toBe(25_00);

        shopSoldOutByOrder($sixty);
        shopSoldOutViewer(ShopperKind::Individual);
        $card = shopSoldOutCard($url, $p['product']);

        expect($card['price']['now']['minor'])->toBe(25_00)
            ->and($card['price']['from']['minor'])->toBe(25_00)
            ->and($listing()->card_variant_id)->toBe($eighty)
            ->and($listing()->retail_card_variant_id)->toBe($eighty);

        // "From" only while the sizes left differ: the two left.
        shopSoldOutByOrder($p['variants'][2]);
        shopSoldOutViewer(ShopperKind::Individual);
        $card = shopSoldOutCard($url, $p['product']);

        expect($card['price']['now']['minor'])->toBe(25_00)
            ->and($card['price']['from'])->toBeNull();

        $values = array_column(shopSoldOutProps($url)['filters']['attributes'][0]['choices'], 'name');

        expect($values)->toBe(['80 cm']);
    });

    it('draws a sold-out size greyed, never offered, in both languages', function (string $locale) {
        $category = Px::category('Kitchens');
        $p = shopSoldOutProduct($category, ['60 cm', '80 cm'], ['30.00', '25.00']);
        [$sixty, $eighty] = $p['variants'];
        shopSoldOutByOrder($sixty);
        $slug = shopSoldOutSlug('product', $p['product'], $locale);

        $props = shopSoldOutProps("/sa/{$locale}/products/".rawurlencode($slug));
        $names = array_column($props['choices'][0]['values'], 'onSale', 'id');

        expect($props['available'])->toBeTrue()
            ->and($props['address']['noindex'])->toBeFalse()
            // Both are drawn, in the product's order; the sold-out one marked, with nothing that gives a count away.
            ->and(array_column($props['variants'], 'id'))->toBe([$sixty, $eighty])
            ->and(array_column($props['variants'], 'orderable'))->toBe([false, true])
            ->and($props['variants'][0])->not->toHaveKeys(['stock', 'available', 'inStock', 'held'])
            ->and(count($props['choices'][0]['values']))->toBe(2)
            ->and(array_values($names))->toBe([false, true])
            // Sales is asked only of the size left: no Add to Cart for the other, whatever is posted to the page.
            ->and(array_column($props['sections']['sales.cart']['sizes'] ?? [], 'variantId'))->toBe([$eighty]);
    })->with([
        'English' => ['en'],
        'Arabic' => ['ar'],
    ]);

    it('takes a retail sold-out product out of an individual\'s lists, and leaves a company its wholesale side', function () {
        $category = Px::category('Kitchens');
        $p = shopSoldOutProduct($category, ['60 cm', '80 cm'], ['30.00', '25.00']);
        [$retail, $wholesale] = $p['variants'];
        $other = shopSoldOutProduct($category, ['100 cm']);
        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $p['product'], [$retail => ['retail' => true, 'wholesale' => false], $wholesale => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5)));
        $url = '/sa/en/categories/'.shopSoldOutSlug('category', $category);
        $productUrl = '/sa/en/products/'.shopSoldOutSlug('product', $p['product']);

        shopSoldOutByOrder($retail);

        shopSoldOutViewer(ShopperKind::Individual);
        expect(shopSoldOutIds($url))->toBe([$other['product']])
            ->and(shopSoldOutProps($productUrl)['available'])->toBeFalse();

        foreach ([ShopperKind::Company, ShopperKind::Guest] as $viewer) {
            shopSoldOutViewer($viewer);
            $card = shopSoldOutCard($url, $p['product']);
            $page = shopSoldOutProps($productUrl);

            expect($card['price']['now'] ?? null)->toBeNull()
                ->and($card['price']['wholesaleFrom']['minor'])->toBe(25_00)
                ->and($page['available'])->toBeTrue()
                ->and(array_column($page['variants'], 'orderable'))->toBe([false, true])
                ->and(array_column($page['choices'][0]['values'], 'onSale'))->toBe([false, true]);
        }

        // A filter by side finds it only on the side left.
        shopSoldOutViewer(ShopperKind::Guest);
        expect(shopSoldOutIds("{$url}?side=retail"))->toBe([$other['product']])
            ->and(shopSoldOutIds("{$url}?side=wholesale"))->toBe([$p['product']]);
    });

    it('and the reverse: wholesale sold out, retail left, with no "Wholesale from" and no wholesale side', function () {
        $category = Px::category('Kitchens');
        $p = shopSoldOutProduct($category, ['60 cm', '80 cm'], ['30.00', '25.00']);
        [$retail, $wholesale] = $p['variants'];
        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $p['product'], [$retail => ['retail' => true, 'wholesale' => false], $wholesale => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5)));
        $url = '/sa/en/categories/'.shopSoldOutSlug('category', $category);
        $productUrl = '/sa/en/products/'.shopSoldOutSlug('product', $p['product']);

        shopSoldOutViewer(ShopperKind::Guest);
        expect(shopSoldOutCard($url, $p['product'])['price']['wholesaleFrom']['minor'])->toBe(25_00);

        shopSoldOutByOrder($wholesale);

        foreach ([ShopperKind::Company, ShopperKind::Guest] as $viewer) {
            shopSoldOutViewer($viewer);
            $card = shopSoldOutCard($url, $p['product']);
            $page = shopSoldOutProps($productUrl);

            expect($card['price']['now']['minor'])->toBe(30_00)
                ->and($card['price']['wholesaleFrom'])->toBeNull()
                ->and(array_column($page['variants'], 'orderable'))->toBe([true, false])
                // Pricing's section is asked only of a size left.
                ->and($page['sections']['pricing.wholesale'] ?? null)->toBeNull();
        }

        shopSoldOutViewer(ShopperKind::Guest);
        // Nothing left on the wholesale side: the filter no longer offers it (P14).
        expect(array_column(shopSoldOutProps($url)['filters']['sides'], 'id'))->toBe(['retail'])
            ->and(shopSoldOutIds("{$url}?side=retail"))->toBe([$p['product']]);

        // An individual never saw the wholesale size, sold out or not.
        shopSoldOutViewer(ShopperKind::Individual);
        expect(array_column(shopSoldOutProps($productUrl)['variants'], 'id'))->toBe([$retail]);
    });
});

describe('back in stock', function () {
    it('comes back on every list, its page available, and the cached page replaced', function () {
        $category = Px::category('Kitchens');
        $p = shopSoldOutProduct($category, ['60 cm', '80 cm'], ['30.00', '25.00']);
        [$sixty, $eighty] = $p['variants'];
        $name = (string) DB::table('catalog.products')->where('id', $p['product'])->value('name_en');
        $urls = [
            'category' => '/sa/en/categories/'.shopSoldOutSlug('category', $category),
            'brand' => '/sa/en/brands/'.shopSoldOutSlug('brand', shopSoldOutBrandOf($p['product'])),
            'search' => '/sa/en/search?q='.rawurlencode($name),
        ];
        $productUrl = '/sa/en/products/'.shopSoldOutSlug('product', $p['product']);
        $version = static fn (): mixed => DB::table('cache')->where('key', config('cache.prefix').'catalog:shop:version')->value('value');

        // Kept in the cache, on sale.
        expect(shopSoldOutProps($productUrl)['available'])->toBeTrue();
        $kept = $version();

        $first = shopSoldOutByOrder($sixty);
        $second = shopSoldOutByOrder($eighty);

        // Replaced by Inventory's push: the kept page is no longer served.
        expect($version())->not->toBe($kept)
            ->and(shopSoldOutProps($productUrl)['available'])->toBeFalse();

        foreach ($urls as $url) {
            expect(shopSoldOutIds($url))->not->toContain($p['product']);
        }

        expect(shopSoldOutProps($urls['category'])['soldOut'])->toBeTrue();
        $gone = $version();

        app(InventoryApi::class)->release($second);

        expect($version())->not->toBe($gone);

        foreach ($urls as $url) {
            expect(shopSoldOutIds($url))->toContain($p['product']);
        }

        $page = shopSoldOutProps($productUrl);

        expect($page['available'])->toBeTrue()
            ->and(array_column($page['variants'], 'orderable'))->toBe([false, true])
            ->and(shopSoldOutCard($urls['category'], $p['product'])['price']['now']['minor'])->toBe(25_00);

        // The other too, by a count: the card goes back to the first size, its price with it.
        app(InventoryApi::class)->release($first);

        expect(shopSoldOutCard($urls['category'], $p['product'])['price']['now']['minor'])->toBe(30_00)
            ->and(array_column(shopSoldOutProps($productUrl)['variants'], 'orderable'))->toBe([true, true]);
    });
});

describe('what stock does not limit', function () {
    it('keeps a wired ordinary product the provider reports at 0 on every list', function () {
        Ix::wired();
        $category = Px::category('Kitchens');
        $p = Px::ready(['60 cm'], $category);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        Rx::set('sa', [$p['variants'][0] => '30.00']);
        Ix::switchOff('sa', $p['variants']);
        shopSoldOutProviderSays($p['variants'][0], 3, 'read-1');
        shopSoldOutProviderSays($p['variants'][0], 0, 'read-2');
        $url = '/sa/en/categories/'.shopSoldOutSlug('category', $category);

        expect(shopSoldOutIds($url))->toBe([$p['product']])
            ->and(array_column(shopSoldOutProps('/sa/en/products/'.shopSoldOutSlug('product', $p['product']))['variants'], 'orderable'))->toBe([true]);
    });

    it('takes a wired stock-dependent size at 0 off, as any other', function () {
        Ix::wired();
        $category = Px::category('Kitchens');
        $p = Px::ready(['60 cm'], $category);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        Rx::set('sa', [$p['variants'][0] => '30.00']);
        shopSoldOutProviderSays($p['variants'][0], 3, 'read-1');

        expect(shopSoldOutProps('/sa/en/products/'.shopSoldOutSlug('product', $p['product']))['available'])->toBeTrue();

        shopSoldOutProviderSays($p['variants'][0], 0, 'read-2');

        expect(shopSoldOutProps('/sa/en/products/'.shopSoldOutSlug('product', $p['product']))['available'])->toBeFalse();
    });

    it('reads a size Inventory never told of as §1.3\'s rule: orderable', function () {
        $category = Px::category('Kitchens');
        $p = Px::ready(['60 cm'], $category);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        Rx::set('sa', [$p['variants'][0] => '30.00']);
        DB::table('catalog.store_variant_facts')->where('variant_id', $p['variants'][0])->update(['orderable' => null]);
        DB::transaction(fn () => app(ListingRows::class)->refresh([$p['product']]));

        expect(DB::table('catalog.listing')->where('product_id', $p['product'])->where('store_id', Fx::storeId('sa'))->pluck('orderable')->unique()->all())->toBe([true]);
    });
});

describe('Sales, asked directly', function () {
    it('still refuses a sold-out size posted to the cart when the order is started', function () {
        Queue::fake();
        ['customer' => $customer, 'address' => $address, 'variants' => $variants] = Sx::ready();
        Ix::adjust('sa', $variants[0], 'REMOVE', 100, 'DAMAGED_LOST');

        // Permissive on the way in (sales.md §1.1); the quote is what refuses it.
        Fx::actAsCustomer($customer);
        Sx::add($variants[0], 1);

        $cart = app(ViewCartHandler::class)->handle(new ViewCart(Fx::storeId('sa'), 'en'));
        $line = array_values(array_filter($cart->lines, static fn (CartLineView $line): bool => $line->variantId === $variants[0]))[0];

        expect($line->problem)->toBe(CartLineProblem::OutOfStock)
            ->and(fn () => Sx::start($address))->toThrow(NotOrderable::class);
    });
});

describe('the queries', function () {
    it('asks no more of a page with a size sold out, for a guest, in production\'s session', function () {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        FakeBreachList::install();
        RecordingSecurityMessages::install();
        $category = Px::category('Kitchens');
        $p = shopSoldOutProduct($category, ['60 cm', '80 cm'], ['30.00', '25.00']);
        shopSoldOutProduct($category, ['100 cm']);
        $pages = [
            'category' => '/sa/en/categories/'.shopSoldOutSlug('category', $category),
            'brand' => '/sa/en/brands/'.shopSoldOutSlug('brand', shopSoldOutBrandOf($p['product'])),
            'product' => '/sa/en/products/'.shopSoldOutSlug('product', $p['product']),
            'search' => '/sa/en/search?q=Drawer',
        ];
        $measure = static function () use ($pages): array {
            $browser = new AdminBrowser('10.13.2.10');
            $counts = [];

            foreach ($pages as $name => $url) {
                $browser->get($url)->assertOk();
                DB::flushQueryLog();
                DB::enableQueryLog();
                $browser->get($url)->assertOk();
                DB::disableQueryLog();
                $counts[$name] = count(DB::getQueryLog());
            }

            return $counts;
        };

        $before = $measure();
        shopSoldOutByOrder($p['variants'][0]);

        expect($measure())->toBe($before);
    });

    it('records a product\'s page with every size sold out, for a guest, in production\'s session', function () {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        FakeBreachList::install();
        RecordingSecurityMessages::install();
        $p = shopSoldOutProduct(Px::category('Kitchens'), ['60 cm', '80 cm']);
        $url = '/sa/en/products/'.shopSoldOutSlug('product', $p['product']);
        $browser = new AdminBrowser('10.13.2.11');
        $now = CarbonImmutable::now();
        $author = Fx::customer();

        $inStock = shopSoldOutQueries($browser, $url);

        foreach ($p['variants'] as $size) {
            shopSoldOutByOrder($size);
        }

        $noFeedback = shopSoldOutQueries($browser, $url);

        Fb::reviewRow($p['product'], $p['variants'][0], $author, ['status' => 'APPROVED', 'moderated_by' => Fx::staff(), 'moderated_at' => $now]);
        DB::table('feedback.product_ratings')->insert(['product_id' => $p['product'], 'rating_sum' => 4, 'rating_count' => 1, 'updated_at' => $now]);
        DB::table('feedback.questions')->insert([
            'id' => strtolower((string) Str::ulid()), 'product_id' => $p['product'], 'store_id' => Fx::storeId('sa'), 'customer_id' => $author,
            'body' => 'Does it fit?', 'locale' => 'en', 'status' => 'ANSWERED', 'answer' => 'Yes.', 'answered_by' => Fx::staff(),
            'answered_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $withFeedback = shopSoldOutQueries($browser, $url);
        /** @var array{props: array{available: bool, sections: array<string, array<string, list<mixed>>>}} $drawn */
        $drawn = json_decode((string) json_encode($browser->get($url)->viewData('page')), true);

        // The rows the count is of: both drawn, on a page with nothing to buy.
        expect($drawn['props']['available'])->toBeFalse()
            ->and($drawn['props']['sections']['feedback.reviews']['reviews'])->toHaveCount(1)
            ->and($drawn['props']['sections']['feedback.questions']['questions'])->toHaveCount(1);

        Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $p['product'])));
        $markedByStaff = shopSoldOutQueries($browser, $url);

        // Measured 2026-11-05 (amendment 40), warm, 2 of each the session's row. Marked by staff, the plain
        // page, as every "Not available now" was before amendment 40: 8, none of them Feedback's or Sales's.
        // Only sold out: that and Feedback's one statement; with a review and an answer, its 3 more - the
        // authors' names 1, the size bought 2 (Catalog's variants and their values); the stores' names are
        // the frame's read, none here. Less than the page in stock, which has Add to Cart. The limits are a
        // target, not a cap (owner, 2026-10-19).
        expect(['in stock' => $inStock, 'sold out' => $noFeedback, 'sold out, with reviews and answers' => $withFeedback, 'marked by staff' => $markedByStaff])
            ->toBe(['in stock' => 18, 'sold out' => 11, 'sold out, with reviews and answers' => 14, 'marked by staff' => 10]); // Content's frame, 2 more since 2026-11-11 (content.md §1.5, plan PR 9): its one cached read, the cache's version and copy.
    });

    it('records an emptied category and brand, for a guest, in production\'s session', function () {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        FakeBreachList::install();
        RecordingSecurityMessages::install();
        $top = Px::category('Kitchens');
        $sub = Px::category('Drawers', $top);
        $p = shopSoldOutProduct($sub, ['60 cm']);
        $pages = [
            'sub-category' => '/sa/en/categories/'.shopSoldOutSlug('category', $sub),
            'top category' => '/sa/en/categories/'.shopSoldOutSlug('category', $top),
            'brand' => '/sa/en/brands/'.shopSoldOutSlug('brand', shopSoldOutBrandOf($p['product'])),
        ];
        $browser = new AdminBrowser('10.13.2.12');
        $measure = static fn (): array => array_map(static fn (string $url): int => shopSoldOutQueries($browser, $url), $pages);

        $onSale = $measure();
        shopSoldOutByOrder($p['variants'][0]);

        // Measured 2026-11-05, warm, 2 of each the session's row. Emptied, a category asks 2 more:
        // whether only stock or a price emptied it (`onlySoldOut`, amendment 39(j)) and its way up
        // (`categoryTrail`, amendment 40(d)), which a top category asks too and finds nothing above it. A brand
        // 1 more: `onlySoldOut`, for its sentence. A page still listing asks neither.
        expect(['on sale' => $onSale, 'emptied' => $measure()])
            ->toBe(['on sale' => ['sub-category' => 10, 'top category' => 10, 'brand' => 10], 'emptied' => ['sub-category' => 12, 'top category' => 12, 'brand' => 11]]); // Content's frame, 2 more since 2026-11-11 (content.md §1.5, plan PR 9): its one cached read, the cache's version and copy.
    });
});

describe('until it is priced (owner, 2026-11-03)', function () {
    it('counts a size with no price for nothing, moving the card, and brings the product back with Pricing\'s push', function () {
        $category = Px::category('Kitchens');
        $p = Px::ready(['60 cm', '80 cm'], $category);
        [$sixty, $eighty] = $p['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        Ix::count('sa', [$sixty => 5, $eighty => 5]);
        $other = shopSoldOutProduct($category, ['100 cm']);
        $url = '/sa/en/categories/'.shopSoldOutSlug('category', $category);
        $productUrl = '/sa/en/products/'.shopSoldOutSlug('product', $p['product']);
        $version = static fn (): mixed => DB::table('cache')->where('key', config('cache.prefix').'catalog:shop:version')->value('value');

        // In stock, no price: as a product sold out, off the lists, its page "Not available now" and still listed.
        $page = shopSoldOutProps($productUrl);

        expect(shopSoldOutIds($url))->toBe([$other['product']])
            ->and(array_column(shopSoldOutProps($url)['filters']['attributes'][0]['choices'], 'name'))->not->toContain('60 cm')
            ->and($page['available'])->toBeFalse()
            ->and($page['address']['noindex'])->toBeFalse();

        // One price: only that size counts; the other is drawn greyed, never offered, in `facts` first.
        $kept = $version();
        Rx::set('sa', [$eighty => '25.00']);

        expect($version())->not->toBe($kept)
            ->and(DB::table('catalog.store_variant_facts')->where('store_id', Fx::storeId('sa'))->where('variant_id', $eighty)->value('price_minor'))->toBe(25_00);

        $page = shopSoldOutProps($productUrl);
        $card = shopSoldOutCard($url, $p['product']);

        expect($page['available'])->toBeTrue()
            ->and(array_column($page['variants'], 'orderable'))->toBe([false, true])
            ->and(array_column($page['choices'][0]['values'], 'onSale'))->toBe([false, true])
            ->and(array_column($page['sections']['sales.cart']['sizes'], 'variantId'))->toBe([$eighty])
            ->and($card['price']['now']['minor'])->toBe(25_00)
            ->and($card['price']['from'])->toBeNull();

        // Both: the first size's card, and "From".
        Rx::set('sa', [$sixty => '30.00']);
        $card = shopSoldOutCard($url, $p['product']);

        expect($card['price']['now']['minor'])->toBe(30_00)
            ->and($card['price']['from']['minor'])->toBe(25_00);

        // Its price taken away, it leaves again.
        Rx::remove('sa', [$sixty, $eighty]);

        expect(shopSoldOutIds($url))->toBe([$other['product']])
            ->and(shopSoldOutProps($productUrl)['available'])->toBeFalse();
    });

    it('takes a side away with its prices: the retail size unpriced, an individual sees nothing, a company the wholesale side', function () {
        $category = Px::category('Kitchens');
        $p = Px::ready(['60 cm', '80 cm'], $category);
        [$retail, $wholesale] = $p['variants'];
        Fx::asSystem(function () use ($p, $retail, $wholesale): void {
            app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true));
            app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $p['product'], [$retail => ['retail' => true, 'wholesale' => false], $wholesale => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5));
        });
        Ix::count('sa', [$retail => 5, $wholesale => 5]);
        Rx::set('sa', [$wholesale => '25.00']);
        $url = '/sa/en/categories/'.shopSoldOutSlug('category', $category);
        $productUrl = '/sa/en/products/'.shopSoldOutSlug('product', $p['product']);

        shopSoldOutViewer(ShopperKind::Individual);
        $individual = shopSoldOutProps($productUrl);
        expect(shopSoldOutProps($url)['soldOut'])->toBeTrue()
            ->and($individual['available'])->toBeFalse()
            ->and($individual['address']['noindex'])->toBeFalse();

        shopSoldOutViewer(ShopperKind::Company);
        $card = shopSoldOutCard($url, $p['product']);
        expect($card['price']['wholesaleFrom']['minor'])->toBe(25_00)
            ->and($card['price']['now'] ?? null)->toBeNull();
    });

    it('draws a store with no prices at all as an empty shop, every page answering', function () {
        $category = Px::category('Kitchens');
        $p = Px::ready(['60 cm'], $category);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        Ix::count('sa', [$p['variants'][0] => 5]);
        $name = (string) DB::table('catalog.products')->where('id', $p['product'])->value('name_en');
        $brand = shopSoldOutProps('/sa/en/brands/'.shopSoldOutSlug('brand', shopSoldOutBrandOf($p['product'])));

        app()->forgetScopedInstances();
        get('/sa/en')->assertOk();

        expect(shopSoldOutProps('/sa/en/categories/'.shopSoldOutSlug('category', $category))['soldOut'])->toBeTrue()
            ->and($brand['products'])->toBe([])
            ->and($brand['soldOut'])->toBeTrue()
            ->and(shopSoldOutProps('/sa/en/search?q='.rawurlencode($name))['total'])->toBe(0)
            ->and(shopSoldOutSuggested(rawurlencode($name)))->toBe([])
            ->and(shopSoldOutProps('/sa/en/products/'.shopSoldOutSlug('product', $p['product']))['available'])->toBeFalse()
            ->and(shopSoldOutProps('/sa/en/search?q='.rawurlencode($name))['frame']['catalog']['menu'])->toBe([])
            ->and(app(ShopBestSellers::class)->read(StoreId::fromString(Fx::storeId('sa')), 'en', 20)['cards'])->toBe([]);
    });
});

describe('what only stock or a price empties (owner, 2026-11-03)', function () {
    it('answers 404 for a category switched off, holding what is sold out and what is unpriced', function (string $fate) {
        $category = Px::category('Hinges');
        $soldOut = shopSoldOutProduct($category, ['60 cm']);
        $unpriced = Px::ready(['80 cm'], $category);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $unpriced['product'], true)));
        Ix::count('sa', [$unpriced['variants'][0] => 5]);
        shopSoldOutByOrder($soldOut['variants'][0]);
        $url = '/sa/en/categories/'.shopSoldOutSlug('category', $category);

        expect(shopSoldOutProps($url)['soldOut'])->toBeTrue();

        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, $fate)));

        foreach ([ShopperKind::Guest, ShopperKind::Individual, ShopperKind::Company] as $viewer) {
            shopSoldOutViewer($viewer);
            get($url)->assertNotFound();
        }
    })->with(['its products hidden' => ['HIDE'], 'its products left' => ['LEAVE']]);

    it('gives an emptied sub-category the way up to the categories above it, each answering', function () {
        $top = Px::category('Kitchens');
        $middle = Px::category('Drawers', $top);
        $emptied = Px::category('Runners', $middle);
        $sibling = Px::category('Handles', $middle);
        $gone = shopSoldOutProduct($emptied, ['60 cm']);
        shopSoldOutProduct($sibling, ['80 cm']);
        shopSoldOutByOrder($gone['variants'][0]);
        $page = shopSoldOutProps('/sa/en/categories/'.shopSoldOutSlug('category', $emptied));

        expect($page['soldOut'])->toBeTrue()
            ->and(array_column($page['path'], 'slug'))->toBe([shopSoldOutSlug('category', $top), shopSoldOutSlug('category', $middle)]);

        foreach ($page['path'] as $step) {
            app()->forgetScopedInstances();
            get('/sa/en/categories/'.$step['slug'])->assertOk();
        }

        // In Arabic, in Arabic; a category still listing draws its path from the menu, as before.
        expect(array_column(shopSoldOutProps('/sa/ar/categories/'.rawurlencode(shopSoldOutSlug('category', $emptied, 'ar')))['path'], 'slug'))->toBe([shopSoldOutSlug('category', $top, 'ar'), shopSoldOutSlug('category', $middle, 'ar')])
            ->and(array_column(shopSoldOutProps('/sa/en/categories/'.shopSoldOutSlug('category', $sibling))['path'], 'slug'))->toBe([shopSoldOutSlug('category', $top), shopSoldOutSlug('category', $middle)]);
    });

    it('gives a sold-out product whose category emptied the way to that category, each step answering (owner, 2026-11-10)', function () {
        $top = Px::category('Kitchens');
        $middle = Px::category('Drawers', $top);
        $emptied = Px::category('Runners', $middle);
        $sibling = Px::category('Handles', $middle);
        $gone = shopSoldOutProduct($emptied, ['60 cm']);
        shopSoldOutProduct($sibling, ['80 cm']);
        $url = static fn (string $locale): string => "/sa/{$locale}/products/".rawurlencode(shopSoldOutSlug('product', $gone['product'], $locale));
        $way = static fn (string $locale): array => [shopSoldOutSlug('category', $top, $locale), shopSoldOutSlug('category', $middle, $locale), shopSoldOutSlug('category', $emptied, $locale)];

        // On sale, the menu's path, as before.
        expect(array_column(shopSoldOutProps($url('en'))['path'], 'slug'))->toBe($way('en'));

        shopSoldOutByOrder($gone['variants'][0]);

        foreach ([ShopperKind::Guest, ShopperKind::Individual, ShopperKind::Company] as $viewer) {
            shopSoldOutViewer($viewer);
            $page = shopSoldOutProps($url('en'));

            // Out of the menu, its category still answers, so the trail goes on to it: every step a page that opens.
            expect($page['available'])->toBeFalse()
                ->and(array_column($page['path'], 'slug'))->toBe($way('en'))
                ->and(array_column(shopSoldOutProps($url('ar'))['path'], 'slug'))->toBe($way('ar'));

            foreach ($page['path'] as $step) {
                app()->forgetScopedInstances();
                get('/sa/en/categories/'.$step['slug'])->assertOk();
            }
        }

        // Switched off for individuals: its page is not theirs, so no trail for them; companies keep it.
        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $emptied, false)));
        shopSoldOutViewer(ShopperKind::Individual);
        app()->forgetScopedInstances();
        get('/sa/en/categories/'.shopSoldOutSlug('category', $emptied))->assertNotFound();
        expect(shopSoldOutProps($url('en'))['path'])->toBe([]);
        shopSoldOutViewer(ShopperKind::Company);
        expect(array_column(shopSoldOutProps($url('en'))['path'], 'slug'))->toBe($way('en'));
    });

    it('keeps every category above a whole branch emptied answering with its sentence, none of them with a sibling still listing', function () {
        $top = Px::category('Kitchens');
        $middle = Px::category('Drawers', $top);
        $emptied = Px::category('Runners', $middle);
        $gone = shopSoldOutProduct($emptied, ['60 cm']);
        $url = static fn (string $category): string => '/sa/en/categories/'.shopSoldOutSlug('category', $category);
        $viewers = [ShopperKind::Guest, ShopperKind::Individual, ShopperKind::Company];

        foreach ($viewers as $viewer) {
            shopSoldOutViewer($viewer);

            expect(shopSoldOutProps($url($top))['soldOut'])->toBeFalse()
                ->and(shopSoldOutProps($url($middle))['soldOut'])->toBeFalse();
        }

        shopSoldOutByOrder($gone['variants'][0]);

        foreach ($viewers as $viewer) {
            shopSoldOutViewer($viewer);

            foreach ([$top, $middle, $emptied] as $category) {
                $page = shopSoldOutProps($url($category));

                expect($page['soldOut'])->toBeTrue()
                    ->and($page['products'])->toBe([])
                    ->and($page['address']['noindex'])->toBeFalse();
            }

            // Out of the menu, every one; each keeps its way up.
            expect($page['frame']['catalog']['menu'])->toBe([])
                ->and(shopSoldOutProps($url($top))['path'])->toBe([])
                ->and(array_column(shopSoldOutProps($url($middle))['path'], 'slug'))->toBe([shopSoldOutSlug('category', $top)])
                ->and(array_column($page['path'], 'slug'))->toBe([shopSoldOutSlug('category', $top), shopSoldOutSlug('category', $middle)]);
        }
    });

    it('leaves a category with nothing at all for this viewer as it was', function () {
        $none = Px::category('Hinges');
        get('/sa/en/categories/'.shopSoldOutSlug('category', $none))->assertNotFound();

        // Switched off for individuals here: sold out or not, not theirs.
        $closed = Px::category('Kitchens');
        $p = shopSoldOutProduct($closed, ['60 cm']);
        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $closed, false)));
        shopSoldOutByOrder($p['variants'][0]);
        $slug = shopSoldOutSlug('category', $closed);
        $productUrl = '/sa/en/products/'.shopSoldOutSlug('product', $p['product']);

        shopSoldOutViewer(ShopperKind::Individual);
        get("/sa/en/categories/{$slug}")->assertNotFound();
        expect(shopSoldOutProps($productUrl)['address']['noindex'])->toBeTrue();
        shopSoldOutViewer(ShopperKind::Company);
        expect(shopSoldOutProps("/sa/en/categories/{$slug}")['soldOut'])->toBeTrue()
            ->and(shopSoldOutProps($productUrl)['address']['noindex'])->toBeFalse();

        // Wholesale only: an individual was never shown it, in stock or not.
        $wholesale = Px::category('Doors');
        $only = shopSoldOutProduct($wholesale, ['80 cm']);
        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $only['product'], [$only['variants'][0] => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5)));
        shopSoldOutByOrder($only['variants'][0]);
        shopSoldOutViewer(ShopperKind::Individual);
        get('/sa/en/categories/'.shopSoldOutSlug('category', $wholesale))->assertNotFound();
        expect(shopSoldOutProps('/sa/en/products/'.shopSoldOutSlug('product', $only['product']))['address']['noindex'])->toBeTrue();
    });

    it('gives an individual the emptied page when the retail size sold out and a wholesale one is left', function () {
        $category = Px::category('Kitchens');
        $p = shopSoldOutProduct($category, ['60 cm', '80 cm']);
        [$retail, $wholesale] = $p['variants'];
        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $p['product'], [$retail => ['retail' => true, 'wholesale' => false], $wholesale => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5)));
        shopSoldOutByOrder($retail);
        shopSoldOutViewer(ShopperKind::Individual);
        $page = shopSoldOutProps('/sa/en/categories/'.shopSoldOutSlug('category', $category));

        expect($page['soldOut'])->toBeTrue()
            ->and($page['frame']['catalog']['menu'])->toBe([])
            ->and($page['address']['noindex'])->toBeFalse();
    });

    it('forwards the other language\'s address to an emptied category, and draws Arabic the same', function () {
        $category = Px::category('Kitchens');
        $p = shopSoldOutProduct($category, ['60 cm']);
        shopSoldOutByOrder($p['variants'][0]);
        $en = shopSoldOutSlug('category', $category);
        $ar = shopSoldOutSlug('category', $category, 'ar');

        expect(shopSoldOutProps('/sa/ar/categories/'.rawurlencode($ar))['soldOut'])->toBeTrue();
        app()->forgetScopedInstances();
        get('/sa/en/categories/'.rawurlencode($ar))->assertStatus(301)->assertRedirect(url("/sa/en/categories/{$en}"));
    });

    it('says the same on a brand\'s page, and only when stock or prices emptied it', function () {
        $p = shopSoldOutProduct(Px::category('Kitchens'), ['60 cm']);
        $url = '/sa/en/brands/'.shopSoldOutSlug('brand', shopSoldOutBrandOf($p['product']));

        expect(shopSoldOutProps($url)['soldOut'])->toBeFalse();

        shopSoldOutByOrder($p['variants'][0]);
        $page = shopSoldOutProps($url);

        expect($page['soldOut'])->toBeTrue()
            ->and($page['address']['noindex'])->toBeFalse();

        $brand = Px::brand('Tallsen');
        expect(shopSoldOutProps('/sa/en/brands/'.shopSoldOutSlug('brand', $brand))['soldOut'])->toBeFalse();
    });
});

describe('the rest of "Not available now" (owner, 2026-11-03)', function () {
    it('keeps noindex for what is not only sold out: marked by staff, not chosen here, archived', function () {
        $category = Px::category('Kitchens');
        $marked = shopSoldOutProduct($category, ['60 cm']);
        Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $marked['product'])));
        $notChosen = Px::ready(['60 cm'], $category);
        $archived = shopSoldOutProduct($category, ['60 cm']);
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($archived['product'])));

        foreach ([$marked, $notChosen, $archived] as $p) {
            $page = shopSoldOutProps('/sa/en/products/'.shopSoldOutSlug('product', $p['product']));

            expect($page['available'])->toBeFalse()
                ->and($page['address']['noindex'])->toBeTrue();
        }
    });

    it('answers 404 for a draft and an unknown address, as ever', function () {
        $draft = Px::product('Draft');
        get('/sa/en/products/'.shopSoldOutSlug('product', $draft))->assertNotFound();
        get('/sa/en/products/no-such-product')->assertNotFound();
    });
});
