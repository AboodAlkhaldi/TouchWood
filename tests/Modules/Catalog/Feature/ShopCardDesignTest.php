<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\CatalogSettings;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The card to the design (shop-design.md §4.1, §4.3, §10.4, §10.6; catalog.md amendment 48): its brand and unit, how
| many sizes this viewer can order now and, for one, what Add to cart adds; whether it sells wholesale; the next photos;
| and its two flags of its own, Best seller and New arrival, compared with the store's settings as the card is read -
| the settings kept with the menu's copy, which a change to either clears (the owner's option (a), 2026-11-16).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function cardDesignViewer(ShopperKind $kind): void
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
 * A product in Saudi Arabia with the sizes given, each sold as asked (`[retail, wholesale]`), wholesale from 5.
 *
 * @param  list<array{bool, bool}>  $ways
 * @return array{category: string, product: string, sizes: list<string>}
 */
function cardDesignProduct(array $ways, ?string $category = null): array
{
    $category ??= Px::category('Kitchens');
    $p = Px::ready(array_map(static fn (int $n): string => (60 + 20 * $n).' cm', array_keys($ways)), $category);
    $store = Fx::storeId('sa');

    Fx::asSystem(function () use ($p, $store, $ways): void {
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $p['product'], true));
        $terms = [];

        foreach ($p['variants'] as $index => $variant) {
            $terms[$variant] = ['retail' => $ways[$index][0], 'wholesale' => $ways[$index][1]];
        }

        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $p['product'], $terms, wholesaleMinimum: 5));
    });
    Px::onSale('sa', $p['product']);
    Rx::set('sa', array_fill_keys($p['variants'], '30.00'));

    return ['category' => $category, 'product' => $p['product'], 'sizes' => $p['variants']];
}

function cardDesignUrl(string $category): string
{
    return '/sa/en/categories/'.DB::table('catalog.category_slugs')->where('category_id', $category)->where('locale', 'en')->where('is_current', true)->value('slug');
}

/**
 * The category page's cards, by product id.
 *
 * @return array<string, array<string, mixed>>
 */
function cardDesignCards(string $category): array
{
    $cards = [];
    get(cardDesignUrl($category))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$cards): void {
        foreach ($page->toArray()['props']['products'] as $card) {
            $cards[(string) $card['productId']] = $card;
        }
    });

    return $cards;
}

it('gives each card its brand, unit, sizes for this viewer and, for one size, what Add to cart adds', function () {
    $many = cardDesignProduct([[true, false], [true, true], [false, true]]);
    $one = cardDesignProduct([[true, true]], $many['category']);
    $wholesaleOnly = cardDesignProduct([[false, true]], $many['category']);

    // An individual buys retail only: two of the first product's sizes, and the wholesale-only product is not theirs.
    cardDesignViewer(ShopperKind::Individual);
    $individual = cardDesignCards($many['category']);
    expect($individual[$many['product']]['sizes'])->toBe(2)
        ->and($individual[$many['product']]['oneSize'])->toBeNull()
        ->and($individual[$many['product']]['brand'])->toBe((string) DB::table('catalog.brands')->where('id', DB::table('catalog.products')->where('id', $many['product'])->value('brand_id'))->value('name_en'))
        ->and($individual[$many['product']]['unit'])->toBe('PIECE')
        ->and($individual[$many['product']]['sellsWholesale'])->toBeTrue()
        ->and($individual[$one['product']]['oneSize'])->toBe(['variant' => $one['sizes'][0], 'mode' => 'RETAIL', 'minimum' => 1]);

    // A company sees every size; its one wholesale-only size adds at the wholesale minimum (D3).
    cardDesignViewer(ShopperKind::Company);
    $company = cardDesignCards($many['category']);
    expect($company[$many['product']]['sizes'])->toBe(3)
        ->and($company[$wholesaleOnly['product']]['oneSize'])->toBe(['variant' => $wholesaleOnly['sizes'][0], 'mode' => 'WHOLESALE', 'minimum' => 5])
        ->and($company[$one['product']]['oneSize'])->toBe(['variant' => $one['sizes'][0], 'mode' => 'RETAIL', 'minimum' => 1]);
});

it('marks Best seller among the store\'s top N and New arrival within N days, both read with the menu\'s copy', function () {
    $first = cardDesignProduct([[true, false]]);
    $second = cardDesignProduct([[true, false]], $first['category']);
    app(ListingFacts::class)->salesRanks(StoreId::fromString(Fx::storeId('sa')), [$first['product'] => 1, $second['product'] => 2]);
    Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(CatalogSettings::BEST_SELLER_TOP, 'sa', 1)));

    cardDesignViewer(ShopperKind::Guest);
    $cards = cardDesignCards($first['category']);
    // Listed today: new for the default 30 days.
    expect($cards[$first['product']]['bestSeller'])->toBeTrue()
        ->and($cards[$second['product']]['bestSeller'])->toBeFalse()
        ->and($cards[$first['product']]['newArrival'])->toBeTrue();

    // A change to either setting clears the kept copy: the next page reads it at once (option (a)).
    Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(CatalogSettings::BEST_SELLER_TOP, 'sa', 2)));
    Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(CatalogSettings::NEW_ARRIVAL_DAYS, 'sa', 0)));
    cardDesignViewer(ShopperKind::Guest);
    $cards = cardDesignCards($first['category']);
    expect($cards[$second['product']]['bestSeller'])->toBeTrue()
        ->and($cards[$first['product']]['newArrival'])->toBeFalse();
});

it('keeps New arrival to the days after the product was first listed in the store', function () {
    $p = cardDesignProduct([[true, false]]);
    // Listed 40 days ago: past the default 30.
    DB::table('catalog.store_products')->where('product_id', $p['product'])->update(['created_at' => now()->subDays(40)]);
    DB::transaction(fn () => app(ListingRows::class)->refresh([$p['product']]));

    cardDesignViewer(ShopperKind::Guest);
    expect(cardDesignCards($p['category'])[$p['product']]['newArrival'])->toBeFalse();
});

it('gives the product page its own Best seller and New arrival, and its unit of sale (shop-design.md §6.3)', function () {
    $p = cardDesignProduct([[true, false]]);
    app(ListingFacts::class)->salesRanks(StoreId::fromString(Fx::storeId('sa')), [$p['product'] => 1]);
    DB::table('catalog.products')->where('id', $p['product'])->update(['unit' => 'PAIR']);
    $slug = (string) DB::table('catalog.product_slugs')->where('product_id', $p['product'])->where('locale', 'en')->where('is_current', true)->value('slug');

    cardDesignViewer(ShopperKind::Guest);
    get('/sa/en/products/'.$slug)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('bestSeller', true)
        ->where('newArrival', true)
        ->where('unit', 'PAIR'));

    // Compared as it is shown, never kept: past the days, the same kept page is no longer new.
    Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(CatalogSettings::NEW_ARRIVAL_DAYS, 'sa', 0)));
    cardDesignViewer(ShopperKind::Guest);
    get('/sa/en/products/'.$slug)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('newArrival', false)->where('bestSeller', true));
});
