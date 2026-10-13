<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Catalog\Application\Query\Shop\ShopPagesCache;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseListLocks;

/*
| A list's lock is transaction-scoped: taken outside a transaction it would be let go at once, so
| it is refused there (review of step 2). Every test that runs against the database is inside
| RefreshDatabase's transaction, so this is checked without one.
|
| Taking one replaces the shop's cached pages' version first (catalog.md §4.5, P21): every change
| meets the version before any list's lock, so none waits on another in a circle.
*/

/**
 * A connection at a given transaction level that only keeps the selects it is sent, and what
 * happened in order.
 */
final class CatalogListLocksConnection extends Connection
{
    /** @var list<array{string, array<array-key, mixed>}> */
    public array $selects = [];

    /** @var list<string> */
    public array $happened = [];

    public function __construct(private readonly int $level) {}

    public function transactionLevel()
    {
        return $this->level;
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  array<array-key, mixed>  $fetchUsing
     * @return list<mixed>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->selects[] = [(string) $query, (array) $bindings];
        $this->happened[] = 'lock';

        return [];
    }
}

final readonly class CatalogListLocksPages implements ShopPagesCache
{
    public function __construct(private CatalogListLocksConnection $db) {}

    public function remember(string $part, Closure $load): mixed
    {
        return $load();
    }

    public function invalidate(): void
    {
        $this->db->happened[] = 'invalidate';
    }

    public function prune(): int
    {
        return 0;
    }
}

it('refuses to take a list\'s lock outside a transaction', function () {
    $db = new CatalogListLocksConnection(0);

    expect(fn () => (new DatabaseListLocks($db, new CatalogListLocksPages($db)))->lock(ListLocks::BRANDS))->toThrow(LogicException::class, 'brands')
        ->and($db->selects)->toBe([])
        ->and($db->happened)->toBe([]);
});

it('takes the list\'s own advisory lock inside one, the shop\'s pages told first', function () {
    $db = new CatalogListLocksConnection(1);

    (new DatabaseListLocks($db, new CatalogListLocksPages($db)))->lock(ListLocks::LABELS);

    expect($db->selects)->toBe([['SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['catalog:labels']]])
        ->and($db->happened)->toBe(['invalidate', 'lock']);
});

it('leaves the shop\'s pages alone for the word pairs, which change only what a search finds', function () {
    $db = new CatalogListLocksConnection(1);

    (new DatabaseListLocks($db, new CatalogListLocksPages($db)))->lock(ListLocks::WORD_PAIRS);

    expect($db->happened)->toBe(['lock']);
});
