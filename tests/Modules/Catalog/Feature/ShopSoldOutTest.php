<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
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
            // A direct link: the one page, nothing to choose, nothing to buy, out of search engines.
            ->and($product['available'])->toBeFalse()
            ->and($product['address']['noindex'])->toBeTrue()
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
        get('/sa/en/categories/'.shopSoldOutSlug('category', $alone))->assertNotFound();
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
            expect(in_array($p['product'], str_contains($url, '/categories/') ? [] : shopSoldOutIds($url), true))->toBeFalse();
        }

        get($urls['category'])->assertNotFound();
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
        DB::table('catalog.store_variant_facts')->where('variant_id', $p['variants'][0])->delete();
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
});
