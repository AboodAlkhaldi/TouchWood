<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Shared\Application\Actor;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The Save heart on the shop's product cards (feedback.md §2.4 B5; catalog.md amendment 32): each page of
| cards carries `cardFacts` — `feedback.saved` for the products the signed-in customer saved in this store —
| read for this viewer on every request, never kept with the page; a guest and a product not saved, nothing.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Two products of one category, chosen in both stores.
 *
 * @return array{category: string, slug: string, first: string, second: string}
 */
function heartsCategory(): array
{
    $category = Px::category('Drawers');
    $products = [];

    foreach ([1, 2] as $n) {
        $id = Px::ready(['60 cm'], $category)['product'];

        foreach (['sa', 'eg'] as $store) {
            Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $id, true)));
        }

        $products[] = $id;
    }

    $slug = (string) DB::table('catalog.category_slugs')->where('category_id', $category)->where('locale', 'en')->where('is_current', true)->value('slug');

    return ['category' => $category, 'slug' => $slug, 'first' => $products[0], 'second' => $products[1]];
}

function heartsSave(string $customerId, string $productId, string $storeCode): void
{
    DB::table('feedback.wishlist_items')->insert(['customer_id' => $customerId, 'product_id' => $productId, 'store_id' => Fx::storeId($storeCode), 'saved_at' => CarbonImmutable::now()]);
}

it('marks on a category page the cards the signed-in customer saved in this store, and only those', function () {
    ['slug' => $slug, 'first' => $first, 'second' => $second] = heartsCategory();
    $sara = Fx::customer();
    Fx::actAsCustomer($sara);
    heartsSave($sara, $first, 'sa');
    // Saved in another store: not this store's heart.
    heartsSave($sara, $second, 'eg');

    get("/sa/en/categories/{$slug}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where("cardFacts.{$first}", ['feedback.saved' => ['saved' => true]])
        ->missing("cardFacts.{$second}"));
});

it('gives a guest no hearts, and the same page to the next viewer, never kept', function () {
    ['slug' => $slug, 'first' => $first] = heartsCategory();
    $sara = Fx::customer();
    Fx::actAsCustomer($sara);
    heartsSave($sara, $first, 'sa');

    // Sara's page first, then a guest's and another customer's: neither sees Sara's heart.
    get("/sa/en/categories/{$slug}")->assertOk();
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
    $guest = get("/sa/en/categories/{$slug}");
    Fx::actAsCustomer(Fx::customer('omar@example.test'));
    $omar = get("/sa/en/categories/{$slug}");

    $guest->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('cardFacts', []));
    $omar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('cardFacts', []));
});
