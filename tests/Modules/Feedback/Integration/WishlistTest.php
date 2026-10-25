<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Feedback\Application\Command\RemoveFromWishlist\RemoveFromWishlist;
use Modules\Feedback\Application\Command\RemoveFromWishlist\RemoveFromWishlistHandler;
use Modules\Feedback\Application\Command\SaveToWishlist\SaveToWishlist;
use Modules\Feedback\Application\Command\SaveToWishlist\SaveToWishlistHandler;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Modules\Feedback\Domain\Exception\WishlistFull;
use Modules\Feedback\Domain\Repository\Wishlist;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseWishlist;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| The wishlist (feedback.md §1.4, §1.5, §6, §7; answers 17, 19, 20): a signed-in customer saves a
| product in an open store that sells it — once per store — at most 500 items in all; removes any item,
| whatever became of it; an anonymized account's list is deleted.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function saveToWishlist(string $customerId, string $productId, string $storeCode = 'sa'): void
{
    Fx::actAsCustomer($customerId);
    app(SaveToWishlistHandler::class)->handle(new SaveToWishlist(Fx::storeId($storeCode), $productId));
}

function removeFromWishlist(string $customerId, string $productId, string $storeCode = 'sa'): void
{
    Fx::actAsCustomer($customerId);
    app(RemoveFromWishlistHandler::class)->handle(new RemoveFromWishlist(Fx::storeId($storeCode), $productId));
}

/**
 * The customer's items as [product, store code] pairs, sorted.
 *
 * @return array<int, array{string, string}>
 */
function wishlistOf(string $customerId): array
{
    return DB::table('feedback.wishlist_items as item')->join('platform.stores as store', 'store.id', '=', 'item.store_id')
        ->where('item.customer_id', $customerId)->get(['item.product_id', 'store.code'])
        ->map(fn (stdClass $row): array => [(string) $row->product_id, (string) $row->code])->sort()->values()->all();
}

/**
 * The real list, counted as if it already held $already more items: the cap without 500 products.
 */
function wishlistCountingFrom(int $already, ?ConnectionInterface $db = null): Wishlist
{
    return new readonly class($already, new DatabaseWishlist($db ?? DB::connection())) implements Wishlist
    {
        public function __construct(private int $already, private Wishlist $real) {}

        public function count(string $customerId): int
        {
            return $this->real->count($customerId) + $this->already;
        }

        public function holds(string $customerId, string $productId, StoreId $store): bool
        {
            return $this->real->holds($customerId, $productId, $store);
        }

        public function savedAmong(string $customerId, StoreId $store, array $productIds): array
        {
            return $this->real->savedAmong($customerId, $store, $productIds);
        }

        public function save(string $customerId, string $productId, StoreId $store, DateTimeImmutable $at): void
        {
            $this->real->save($customerId, $productId, $store, $at);
        }

        public function remove(string $customerId, string $productId, StoreId $store): void
        {
            $this->real->remove($customerId, $productId, $store);
        }

        public function clear(string $customerId): void
        {
            $this->real->clear($customerId);
        }
    };
}

describe('saving', function () {
    it('saves a product in the store being browsed, once however often', function () {
        ['product' => $product] = Fb::sold();
        $sara = Fx::customer();
        CarbonImmutable::setTestNow('2026-10-19 10:00:00');
        saveToWishlist($sara, $product);
        CarbonImmutable::setTestNow('2026-10-19 11:00:00');
        saveToWishlist($sara, strtoupper(" {$product} "));

        expect(wishlistOf($sara))->toBe([[$product, 'sa']])
            ->and(CarbonImmutable::parse((string) DB::table('feedback.wishlist_items')->value('saved_at'))->equalTo(CarbonImmutable::parse('2026-10-19 10:00:00')))->toBeTrue();
    });

    it('keeps the same product saved in two stores as two items', function () {
        ['product' => $product] = Fb::sold(['sa', 'ae']);
        $sara = Fx::customer();
        saveToWishlist($sara, $product);
        saveToWishlist($sara, $product, 'ae');

        expect(wishlistOf($sara))->toBe([[$product, 'ae'], [$product, 'sa']]);
    });

    it('refuses what cannot be saved, naming why', function (Closure $save, string $attribute) {
        ['product' => $product] = Fb::sold(['sa', 'eg']);
        $sara = Fx::customer();

        expect(Fb::thrown(fn () => $save($sara, $product), InvalidFeedbackAttribute::class)->attribute)->toBe($attribute)
            ->and(DB::table('feedback.wishlist_items')->count())->toBe(0);
    })->with([
        'a store that does not sell it' => [fn (string $c, string $p) => saveToWishlist($c, $p, 'ae'), 'product'],
        'a product that is not one' => [fn (string $c) => saveToWishlist($c, 'not-a-product'), 'product'],
        'a product that is nothing' => [fn (string $c) => saveToWishlist($c, strtolower((string) Str::ulid())), 'product'],
        'a product not ready' => [fn (string $c) => saveToWishlist($c, Px::product('A draft')), 'product'],
        'a product not available now' => [function (string $c, string $p) {
            Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $p)));
            saveToWishlist($c, $p);
        }, 'product'],
        'a store that is not one' => [function (string $c, string $p) {
            Fx::actAsCustomer($c);
            app(SaveToWishlistHandler::class)->handle(new SaveToWishlist('not-a-store', $p));
        }, 'store'],
        'a store switched off' => [function (string $c, string $p) {
            // An off store is not found by its code any more: its id, kept from before.
            $egypt = Fx::storeId('eg');
            Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
            Fx::actAsCustomer($c);
            app(SaveToWishlistHandler::class)->handle(new SaveToWishlist($egypt, $p));
        }, 'store'],
    ]);

    it('is for a signed-in customer only', function (Closure $actor) {
        ['product' => $product] = Fb::sold();
        $actor();

        expect(fn () => app(SaveToWishlistHandler::class)->handle(new SaveToWishlist(Fx::storeId('sa'), $product)))->toThrow(Unauthorized::class)
            ->and(fn () => app(RemoveFromWishlistHandler::class)->handle(new RemoveFromWishlist(Fx::storeId('sa'), $product)))->toThrow(Unauthorized::class);
    })->with([
        'a Super Admin' => [fn () => Fx::actAsStaff(Fx::staff(superAdmin: true))],
        'a guest' => [fn () => Fx::actAs(Actor::guest(strtolower((string) Str::ulid())))],
    ]);

    it('holds 500 items at most, counted in every store, and saving one already there is no 501st', function () {
        ['product' => $first] = Fb::sold(['sa', 'ae']);
        ['product' => $second] = Fb::sold(['sa', 'ae']);
        $sara = Fx::customer();
        app()->instance(Wishlist::class, wishlistCountingFrom(498));

        saveToWishlist($sara, $first);
        saveToWishlist($sara, $first, 'ae');
        saveToWishlist($sara, $first);

        expect(fn () => saveToWishlist($sara, $second))->toThrow(WishlistFull::class)
            ->and(wishlistOf($sara))->toBe([[$first, 'ae'], [$first, 'sa']]);
    });

    it('counts only the customer\'s own items', function () {
        ['product' => $product] = Fb::sold();
        $sara = Fx::customer();
        $layla = Fx::customer('layla@example.test');
        saveToWishlist($layla, $product);
        app()->instance(Wishlist::class, wishlistCountingFrom(499));

        saveToWishlist($sara, $product);

        expect(wishlistOf($sara))->toBe([[$product, 'sa']]);
    });
});

describe('removing', function () {
    it('removes the item of that store only, and nothing when it is not there', function () {
        ['product' => $product] = Fb::sold(['sa', 'ae']);
        ['product' => $other] = Fb::sold();
        $sara = Fx::customer();
        $layla = Fx::customer('layla@example.test');
        saveToWishlist($sara, $product);
        saveToWishlist($sara, $product, 'ae');
        saveToWishlist($layla, $product);

        removeFromWishlist($sara, strtoupper(" {$product} "));

        expect(wishlistOf($sara))->toBe([[$product, 'ae']]);

        removeFromWishlist($sara, $product);
        removeFromWishlist($sara, $other);
        Fx::actAsCustomer($sara);
        app(RemoveFromWishlistHandler::class)->handle(new RemoveFromWishlist(strtolower((string) Str::ulid()), $product));

        expect(wishlistOf($sara))->toBe([[$product, 'ae']])
            ->and(wishlistOf($layla))->toBe([[$product, 'sa']]);
    });

    it('keeps an archived product and an off store\'s items, and removes them like any other', function () {
        ['product' => $product] = Fb::sold(['sa', 'eg']);
        $sara = Fx::customer();
        saveToWishlist($sara, $product);
        saveToWishlist($sara, $product, 'eg');
        // An off store is not found by its code any more: its id, kept from before.
        $egypt = Fx::storeId('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($product)));

        expect(wishlistOf($sara))->toBe([[$product, 'eg'], [$product, 'sa']]);

        Fx::actAsCustomer($sara);
        app(RemoveFromWishlistHandler::class)->handle(new RemoveFromWishlist($egypt, $product));
        removeFromWishlist($sara, $product);

        expect(wishlistOf($sara))->toBe([]);
    });

    it('refuses ids that are not ones', function (string $store, string $product, string $attribute) {
        $sara = Fx::customer();
        Fx::actAsCustomer($sara);

        expect(Fb::thrown(fn () => app(RemoveFromWishlistHandler::class)->handle(new RemoveFromWishlist($store === 'sa' ? Fx::storeId('sa') : $store, $product)), InvalidFeedbackAttribute::class)->attribute)->toBe($attribute);
    })->with([
        'a store' => ['not-a-store', '01JA0000000000000000000000', 'store'],
        'a product' => ['sa', 'not-a-product', 'product'],
    ]);
});

it('deletes an anonymized account\'s list in every store, once however often told, and no one else\'s', function () {
    ['product' => $product] = Fb::sold(['sa', 'ae']);
    $sara = Fx::customer();
    $layla = Fx::customer('layla@example.test');
    saveToWishlist($sara, $product);
    saveToWishlist($sara, $product, 'ae');
    saveToWishlist($layla, $product);

    event(new CustomerAnonymized((string) Str::uuid(), strtoupper($sara), CarbonImmutable::now()));

    expect(wishlistOf($sara))->toBe([]);

    event(new CustomerAnonymized((string) Str::uuid(), $sara, CarbonImmutable::now()));

    expect(wishlistOf($sara))->toBe([])
        ->and(wishlistOf($layla))->toBe([[$product, 'sa']]);
});

it('lets Catalog delete a product in lists, the items going with it', function () {
    // Only a draft is deleted by hand, and none is saved through the shop: the item written directly.
    $draft = Px::product('A draft');
    $sara = Fx::customer();
    DB::table('feedback.wishlist_items')->insert(['customer_id' => $sara, 'product_id' => $draft, 'store_id' => Fx::storeId('sa'), 'saved_at' => CarbonImmutable::now()]);

    Fx::asSystem(fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($draft)));

    expect(DB::table('catalog.products')->where('id', $draft)->exists())->toBeFalse()
        ->and(wishlistOf($sara))->toBe([]);
});
