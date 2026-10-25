<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Feedback\Application\Command\SaveToWishlist\SaveToWishlist;
use Modules\Feedback\Application\Command\SaveToWishlist\SaveToWishlistHandler;
use Modules\Feedback\Domain\Exception\WishlistFull;
use Modules\Feedback\Domain\Repository\Wishlist;
use Modules\Feedback\Infrastructure\Eloquent\DatabaseWishlist;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\ActorContext;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| Two products saved at once by a customer with 499 items (feedback.md §1.4; answer 20): the second
| waits for the lock on the customer, then counts the first — never 501. The 499 are counted, not
| stored (the real list plus 498), as the wishlist test does: the lock is what is under test.
|
| Outside RefreshDatabase's transaction (lesson 87), as the question race test: it commits its own rows
| and empties the database before and after itself; skipped under --parallel.
*/

function wishlistRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

/** The real list on a connection, counted as if it held 498 more. */
function wishlistRaceList(ConnectionInterface $db): Wishlist
{
    return new readonly class(new DatabaseWishlist($db)) implements Wishlist
    {
        public function __construct(private Wishlist $real) {}

        public function count(string $customerId): int
        {
            return $this->real->count($customerId) + 498;
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

beforeEach(function () {
    if (wishlistRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.wishlist_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (wishlistRaceInParallel()) {
        return;
    }

    DB::connection('wishlist_race')->disconnect();
    // The store directory read during the test is cached: the wipe must not meet the stores it removes.
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

it('lets one of two products saved at once be the 500th, and refuses the other', function () {
    ['product' => $one] = Fb::sold();
    ['product' => $two] = Fb::sold();
    ['product' => $three] = Fb::sold();
    $sara = Fx::customer();
    Fx::actAsCustomer($sara);
    $handler = static fn (ConnectionInterface $db): SaveToWishlistHandler => new SaveToWishlistHandler(app(Authorizer::class), app(ActorContext::class), app(PlatformApi::class), app(CatalogApi::class), wishlistRaceList($db), $db);
    $save = static fn (SaveToWishlistHandler $handler, string $product) => $handler->handle(new SaveToWishlist(Fx::storeId('sa'), $product));

    $first = DB::connection();
    $second = DB::connection('wishlist_race');
    $save($handler($first), $one);
    $second->statement("SET lock_timeout = '500ms'");

    $first->beginTransaction();

    try {
        // The 500th is saved; its lock on the customer is held until its transaction ends.
        $save($handler($first), $two);

        // The 501st, at that moment, stops on the customer's lock and gives up (55P03).
        $waited = null;

        try {
            $save($handler($second), $three);
        } catch (QueryException $timedOut) {
            $waited = $timedOut;
        }

        expect($waited?->getCode())->toBe('55P03')
            ->and((string) $waited?->getSql())->toContain('pg_advisory_xact_lock');

        $first->commit();
    } catch (Throwable $failed) {
        $first->rollBack();

        throw $failed;
    }

    // Once it may, it counts the 500th: one too many.
    expect(fn () => $save($handler($second), $three))->toThrow(WishlistFull::class)
        ->and(DB::table('feedback.wishlist_items')->where('customer_id', $sara)->count())->toBe(2);
})->skip(fn (): bool => wishlistRaceInParallel(), 'Empties its database: run serially.');
