<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Query\Shop\CardRating;
use Modules\Catalog\Application\Query\Shop\CategoryPage;
use Modules\Catalog\Application\Query\Shop\ProductCard;
use Modules\Catalog\Application\Query\Shop\ShopCatalog;
use Modules\Catalog\Application\Query\Shop\ShopFilters;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Ratings in the shop's lists (catalog.md amendment 25; feedback.md §1.2, answer 15): each product's
| rating pushed in by Feedback — one for every store — shown as stars on cards and offered as a sort,
| only where the store's switch is on; best-rated first by a weighted average, products without
| reviews last; best-selling instead where ratings are not shown.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function ratedStore(string $code = 'sa'): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/**
 * A ready product in the category, chosen in the stores.
 *
 * @param  list<string>  $stores
 */
function ratedProduct(string $categoryId, array $stores = ['sa']): string
{
    $id = Px::ready(categoryId: $categoryId)['product'];

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $id, true)));
    }

    return $id;
}

function rate(string $productId, int $sum, int $count): void
{
    DB::transaction(fn () => app(ListingFacts::class)->ratings([$productId => ['sum' => $sum, 'count' => $count]]));
}

function categoryOf(string $categoryId, string $store = 'sa', ?string $sort = null, ?string $after = null, int $limit = 20): CategoryPage
{
    $slug = (string) DB::table('catalog.category_slugs')->where('category_id', $categoryId)->where('locale', 'en')->where('is_current', true)->value('slug');
    $page = app(ShopCatalog::class)->category(ratedStore($store), 'en', ShopperKind::Guest, $slug, ShopFilters::of(sort: $sort), $after, $limit);

    return $page instanceof CategoryPage ? $page : throw new RuntimeException('No page.');
}

/**
 * @return list<string>
 */
function ratedIds(CategoryPage $page): array
{
    return array_map(static fn (ProductCard $card): string => $card->productId, $page->products->cards);
}

describe('stars on cards', function () {
    it('shows a product\'s average to one decimal, half up, with how many — in every store selling it', function () {
        $category = Px::category('Doors');
        $rated = ratedProduct($category, ['sa', 'ae']);
        $unrated = ratedProduct($category, ['sa', 'ae']);
        rate($rated, 17, 4);

        foreach (['sa', 'ae'] as $store) {
            $cards = [];

            foreach (categoryOf($category, $store)->products->cards as $card) {
                $cards[$card->productId] = $card;
            }

            expect($cards[$rated]->rating)->toEqual(new CardRating(4.3, 4))
                ->and($cards[$unrated]->rating)->toBeNull();
        }
    });

    it('rounds the average half up in whole numbers', function (int $sum, int $count, float $average) {
        expect(CardRating::of($sum, $count)->average)->toBe($average);
    })->with([
        'a quarter up' => [17, 4, 4.3],
        'a ninth, rounded down' => [37, 9, 4.1],
        'exactly' => [10, 2, 5.0],
        'one star' => [1, 1, 1.0],
        'a third' => [13, 3, 4.3],
    ]);

    it('shows no stars and offers no rating sort where the store\'s switch is off; the other stores are untouched', function () {
        $category = Px::category('Doors');
        $rated = ratedProduct($category, ['sa', 'ae']);
        rate($rated, 5, 1);

        DB::transaction(fn () => app(ListingFacts::class)->ratingsShown(ratedStore('sa'), false));

        expect(categoryOf($category, 'sa')->products->cards[0]->rating)->toBeNull()
            ->and(categoryOf($category, 'sa')->ratingSort)->toBeFalse()
            ->and(categoryOf($category, 'ae')->products->cards[0]->rating)->not->toBeNull()
            ->and(categoryOf($category, 'ae')->ratingSort)->toBeTrue();

        DB::transaction(fn () => app(ListingFacts::class)->ratingsShown(ratedStore('sa'), true));
        expect(categoryOf($category, 'sa')->products->cards[0]->rating)->not->toBeNull();
    });

    it('leaves out a rating of a product that is not Catalog\'s', function () {
        rate(strtolower((string) Str::ulid()), 5, 1);

        expect(DB::table('catalog.product_ratings')->count())->toBe(0);
    });
});

describe('the rating sort', function () {
    it('puts a product with many good reviews above one with a single perfect one, those without reviews last', function () {
        $category = Px::category('Doors');
        $single = ratedProduct($category);
        $many = ratedProduct($category);
        $poor = ratedProduct($category);
        $none = ratedProduct($category);
        $older = ratedProduct($category);
        rate($single, 5, 1);
        rate($many, 90, 20);
        rate($poor, 4, 2);

        // The store's average C = (5 + 90 + 4) / 23 ≈ 4.30. Twenty reviews at 4.5 score
        // (5 × 4.30 + 90) / 25 ≈ 4.46; one 5★ review (5 × 4.30 + 5) / 6 ≈ 4.42; two at 2 ≈ 3.65.
        // The two without reviews last, the newer first.
        expect(ratedIds(categoryOf($category, sort: ShopFilters::RATING)))->toBe([$many, $single, $poor, $older, $none]);
    });

    it('walks the list page by page by the same order, never skipping or repeating a product', function () {
        $category = Px::category('Doors');
        $made = [];

        foreach ([[5, 1], [9, 2], [9, 2], [3, 1], [0, 0], [0, 0], [20, 5]] as [$sum, $count]) {
            $id = ratedProduct($category);
            $made[] = $id;

            if ($count > 0) {
                rate($id, $sum, $count);
            }
        }

        $whole = ratedIds(categoryOf($category, sort: ShopFilters::RATING));
        $walked = [];
        $after = null;

        do {
            $page = categoryOf($category, sort: ShopFilters::RATING, after: $after, limit: 2);
            array_push($walked, ...ratedIds($page));
            $after = $page->products->next;
        } while ($after !== null);

        expect($walked)->toBe($whole)
            ->and($walked)->toEqualCanonicalizing($made);
    });

    it('orders by newest when no product of the store has a review', function () {
        $category = Px::category('Doors');
        $first = ratedProduct($category);
        $second = ratedProduct($category);

        expect(ratedIds(categoryOf($category, sort: ShopFilters::RATING)))->toBe([$second, $first]);
    });

    it('gives best-selling instead where ratings are not shown', function () {
        $category = Px::category('Doors');
        $first = ratedProduct($category);
        $second = ratedProduct($category);
        rate($first, 5, 1);
        DB::transaction(fn () => app(ListingFacts::class)->ratingsShown(ratedStore('sa'), false));

        $page = categoryOf($category, sort: ShopFilters::RATING);

        expect($page->chosen?->sort)->toBe(ShopFilters::BEST_SELLING)
            ->and(ratedIds($page))->toBe(ratedIds(categoryOf($category)));
    });
});

it('treats a product whose last review was unpublished as one without reviews', function () {
    $category = Px::category('Doors');
    $emptied = ratedProduct($category);
    $rated = ratedProduct($category);
    $newest = ratedProduct($category);
    rate($emptied, 0, 0);
    rate($rated, 3, 1);
    $cards = [];

    foreach (categoryOf($category)->products->cards as $card) {
        $cards[$card->productId] = $card;
    }

    expect($cards[$emptied]->rating)->toBeNull()
        ->and(ratedIds(categoryOf($category, sort: ShopFilters::RATING)))->toBe([$rated, $newest, $emptied]);
});

it('counts only what the store sells in its average', function () {
    $category = Px::category('Doors');
    $low = ratedProduct($category);
    $high = ratedProduct($category);
    $elsewhere = ratedProduct($category, ['ae']);
    rate($low, 4, 1);
    rate($high, 20, 5);
    // Sold only in the UAE: a store average of 1 would put the single 4★ review above the five 4★s.
    rate($elsewhere, 200, 200);

    expect(ratedIds(categoryOf($category, sort: ShopFilters::RATING)))->toBe([$high, $low]);
});
