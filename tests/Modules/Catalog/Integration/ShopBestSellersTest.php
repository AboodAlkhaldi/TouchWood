<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Catalog\Public\Contracts\ShopBestSellers;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| The store's best sellers as the shop's cards (catalog.md amendment 36; sales.md §4.2 B14), for an empty
| cart: what the shopper can order here, best-selling first, then newest, with the cards' prices and what
| the modules add to them.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** A product on sale in Saudi Arabia at this price. */
function bestSeller(string $price): string
{
    ['product' => $product, 'variants' => $variants] = Px::ready();
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $product, true)));
    Rx::switchOn('sa', $variants);
    Rx::set('sa', [$variants[0] => $price]);

    return $product;
}

/**
 * @return array{cards: list<array<string, mixed>>, cardFacts: array<string, array<string, array<string, mixed>>>}
 */
function bestSellers(int $limit, string $locale = 'en'): array
{
    app()->forgetScopedInstances();

    return app(ShopBestSellers::class)->read(StoreId::fromString(Fx::storeId('sa')), $locale, $limit);
}

it('gives the best sellers first, then the newest, as many as asked', function () {
    $older = bestSeller('10.00');
    $second = bestSeller('20.00');
    $first = bestSeller('30.00');
    $newest = bestSeller('40.00');
    app(ListingFacts::class)->salesRanks(StoreId::fromString(Fx::storeId('sa')), [$first => 1, $second => 2]);

    expect(array_column(bestSellers(10)['cards'], 'productId'))->toBe([$first, $second, $newest, $older])
        ->and(array_column(bestSellers(2)['cards'], 'productId'))->toBe([$first, $second])
        // At least one.
        ->and(array_column(bestSellers(0)['cards'], 'productId'))->toBe([$first]);
});

it('draws each card as the shop lists do, with its price, and what the modules add as cardFacts', function () {
    $product = bestSeller('30.00');

    // As the page receives it: the amounts are Platform's MoneyData, written out as JSON.
    $read = json_decode((string) json_encode(bestSellers(5)), true);

    expect($read['cards'][0]['productId'])->toBe($product)
        ->and($read['cards'][0]['price']['now']['minor'] ?? null)->toBe(30_00)
        ->and($read['cards'][0])->toHaveKeys(['name', 'slug', 'photo', 'labels', 'lastPieces', 'rating', 'ratingCount'])
        ->and($read['cardFacts'])->toBeArray();
});

it('leaves out what the shopper cannot order here', function () {
    $sold = bestSeller('30.00');
    $off = bestSeller('20.00');
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $off, false)));

    expect(array_column(bestSellers(5)['cards'], 'productId'))->toBe([$sold]);
});

it('refuses a language but Arabic or English', function () {
    bestSeller('30.00');

    expect(fn () => bestSellers(5, 'fr'))->toThrow(InvalidCatalogAttribute::class);
});
