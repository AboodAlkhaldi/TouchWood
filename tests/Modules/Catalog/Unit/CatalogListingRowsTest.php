<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseListingRows;
use Modules\Platform\Public\Contracts\PlatformApi;

/*
| The listing's rows are written inside the change's transaction, under the products' lock
| (catalog.md §5.4): outside one, a row could be written from what another change is halfway
| through, so it is refused before anything is read. Every test that runs against the database is
| inside RefreshDatabase's transaction, so this is checked without one (lesson 87).
*/

/**
 * A connection outside any transaction that keeps every statement it is sent.
 */
final class CatalogListingRowsConnection extends Connection
{
    /** @var list<string> */
    public array $statements = [];

    public function __construct() {}

    public function transactionLevel()
    {
        return 0;
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @param  array<array-key, mixed>  $fetchUsing
     * @return list<mixed>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->statements[] = (string) $query;

        return [];
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    public function affectingStatement($query, $bindings = [])
    {
        $this->statements[] = (string) $query;

        return 0;
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     */
    public function statement($query, $bindings = [])
    {
        $this->statements[] = (string) $query;

        return true;
    }
}

it('refuses to write rows outside a transaction, before reading anything', function (string $call) {
    $db = new CatalogListingRowsConnection;
    /** @var PlatformApi $platform the rows are refused before Platform is asked anything */
    $platform = Mockery::mock(PlatformApi::class);
    $rows = new DatabaseListingRows($db, $platform);

    expect(fn () => $call === 'refresh' ? $rows->refresh(['01k6abcdefghjkmnpqrstvwxyz']) : $rows->rebuild())
        ->toThrow(LogicException::class, "inside the change's transaction")
        ->and($db->statements)->toBe([]);
})->with(['refresh', 'rebuild']);
