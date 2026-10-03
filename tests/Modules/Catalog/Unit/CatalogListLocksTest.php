<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseListLocks;

/*
| A list's lock is transaction-scoped: taken outside a transaction it would be let go at once, so
| it is refused there (review of step 2). Every test that runs against the database is inside
| RefreshDatabase's transaction, so this is checked without one.
*/

/**
 * A connection at a given transaction level that only keeps the selects it is sent.
 */
final class CatalogListLocksConnection extends Connection
{
    /** @var list<array{string, array<array-key, mixed>}> */
    public array $selects = [];

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

        return [];
    }
}

it('refuses to take a list\'s lock outside a transaction', function () {
    $db = new CatalogListLocksConnection(0);

    expect(fn () => (new DatabaseListLocks($db))->lock(ListLocks::BRANDS))->toThrow(LogicException::class, 'brands')
        ->and($db->selects)->toBe([]);
});

it('takes the list\'s own advisory lock inside one', function () {
    $db = new CatalogListLocksConnection(1);

    (new DatabaseListLocks($db))->lock(ListLocks::LABELS);

    expect($db->selects)->toBe([['SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['catalog:labels']]]);
});
