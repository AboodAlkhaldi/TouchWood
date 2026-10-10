<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Infrastructure\Eloquent\RequestGrantsReader;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Application\Authorizer;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| A staff member's permissions, read once per web request (amendment 65; owner, 2026-10-08). The
| panel asked them once per menu entry - 26 reads of the cache table on a Catalog list page - before
| the page read anything. What it may not do: answer the system from memory, or answer from memory
| for someone whose permissions changed in the same request, whether that change commits or not.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * Every query from now on.
 *
 * @return ArrayObject<int, array{sql: string, bindings: array<array-key, mixed>}>
 */
function requestGrantsRecord(): ArrayObject
{
    /** @var ArrayObject<int, array{sql: string, bindings: array<array-key, mixed>}> $queries */
    $queries = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($queries): void {
        $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    return $queries;
}

/**
 * The reads of a staff member's permissions from the cache table (a miss also writes the new
 * snapshot; only the reads are counted).
 *
 * @param  ArrayObject<int, array{sql: string, bindings: array<array-key, mixed>}>  $queries
 */
function requestGrantsReads(ArrayObject $queries): int
{
    return count(array_filter(
        $queries->getArrayCopy(),
        fn (array $query): bool => str_starts_with($query['sql'], 'select') && str_contains($query['sql'], '"cache"')
            && str_contains(implode(' ', array_map(strval(...), $query['bindings'])), 'access:staff-grants:'),
    ));
}

it('is the reader every caller gets, one per request', function () {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']));
    $reader = app(GrantsReader::class);

    expect($reader)->toBeInstanceOf(RequestGrantsReader::class)
        ->and(app(GrantsReader::class))->toBe($reader);

    app()->forgetScopedInstances();

    expect(app(GrantsReader::class))->not->toBe($reader);
});

it('reads a staff member\'s permissions once in a request, however many times they are asked', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE, PlatformPermissions::SETTINGS_VIEW], ['sa']);
    Fx::actAsStaff($staffId);
    Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa'));

    // A new request: nothing scoped survives. The store is looked up first, so only the
    // permissions are counted below.
    app()->forgetScopedInstances();
    $scope = Fx::inStore('sa');
    $queries = requestGrantsRecord();
    $authorizer = app(Authorizer::class);

    foreach (range(1, 10) as $ignored) {
        $authorizer->storesWith(PlatformPermissions::STORE_UPDATE);
        $authorizer->storesWith(PlatformPermissions::SETTINGS_VIEW);
        $authorizer->isUnlimited();
        Fx::allows(PlatformPermissions::STORE_UPDATE, $scope);
    }

    // The version and the snapshot, once: everything after is answered from the request's memory.
    expect(count($queries))->toBe(2)
        ->and(requestGrantsReads($queries))->toBe(2);
});

it('never answers the system from memory', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    app(GrantsReader::class)->forStaff($staffId);

    Fx::asSystem(function () use ($staffId): void {
        $queries = requestGrantsRecord();
        $reader = app(GrantsReader::class);

        $reader->forStaff($staffId);
        $reader->forStaff($staffId);
        $reader->forStaff($staffId);

        expect(requestGrantsReads($queries))->toBe(6);
    });
});

it('answers a request with the permissions it first read, until the request itself changes them', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    Fx::actAsStaff(Fx::staff());
    $reader = app(GrantsReader::class);

    expect($reader->forStaff($staffId)?->storesFor(PlatformPermissions::STORE_UPDATE)?->storeIds())->toBe([Fx::storeId('sa')]);

    // Another request widens them (Fx acts as the system, in a scope of its own): this request keeps
    // what it read, and the next one sees the change.
    Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE]), ['sa', 'eg']);

    expect($reader->forStaff($staffId)?->storesFor(PlatformPermissions::STORE_UPDATE)?->storeIds())->toBe([Fx::storeId('sa')]);

    // A change in this request forgets them, and from then on they are read from the cache.
    $reader->refresh($staffId);
    $queries = requestGrantsRecord();

    expect($reader->forStaff($staffId)?->storesFor(PlatformPermissions::STORE_UPDATE)?->storeIds())
        ->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('eg')]);
    $reader->forStaff($staffId);

    expect(requestGrantsReads($queries))->toBe(4);
});

it('does not remember a change that rolls back', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    $wider = Fx::role([PlatformPermissions::STORE_UPDATE]);
    Fx::actAsStaff(Fx::staff());
    $reader = app(GrantsReader::class);
    $reader->forStaff($staffId);

    try {
        DB::transaction(function () use ($reader, $staffId, $wider): void {
            Fx::assign($staffId, $wider, ['sa', 'eg']);
            $reader->refresh($staffId);

            // Read inside the change: the new stores, not yet committed.
            expect($reader->forStaff($staffId)?->storesFor(PlatformPermissions::STORE_UPDATE)?->storeIds())->toHaveCount(2);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect($reader->forStaff($staffId)?->storesFor(PlatformPermissions::STORE_UPDATE)?->storeIds())->toBe([Fx::storeId('sa')]);
});

it('keeps each staff member apart', function () {
    $sa = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    $eg = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['eg']);
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
    $reader = app(GrantsReader::class);

    expect($reader->forStaff($sa)?->stores?->storeIds())->toBe([Fx::storeId('sa')])
        ->and($reader->forStaff($eg)?->stores?->storeIds())->toBe([Fx::storeId('eg')])
        ->and($reader->forStaff(strtoupper($sa))?->stores?->storeIds())->toBe([Fx::storeId('sa')]);
});

it('reads through the cache inside a transaction opened after the request began, and keeps nothing it read there', function () {
    // A handler checks its author again after taking its locks (ChangeStaffRole): a revocation it
    // waited on must stop it, as before the request remembered anything.
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    Fx::actAsStaff(Fx::staff());
    $reader = app(GrantsReader::class);
    $reader->forStaff($staffId);

    Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE]), ['sa', 'eg']);

    DB::transaction(function () use ($reader, $staffId): void {
        $queries = requestGrantsRecord();

        expect($reader->forStaff($staffId)?->stores?->storeIds())->toHaveCount(2);
        $reader->forStaff($staffId);

        expect(requestGrantsReads($queries))->toBe(4);
    });

    // Back where the request reads: what it first read, nothing from inside the transaction.
    expect($reader->forStaff($staffId)?->stores?->storeIds())->toBe([Fx::storeId('sa')]);
});

it('remembers that nobody has an id, too', function () {
    Fx::actAsStaff(Fx::staff());
    $nobody = strtolower((string) Str::ulid());
    // Warm: the first time anyone asks, the cache has no version for the id yet.
    app(GrantsReader::class)->forStaff($nobody);
    app()->forgetScopedInstances();
    $reader = app(GrantsReader::class);
    $queries = requestGrantsRecord();

    expect($reader->forStaff($nobody))->toBeNull()
        ->and($reader->forStaff($nobody))->toBeNull()
        ->and(requestGrantsReads($queries))->toBe(2);
});

it('answers one person once however the id is written', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    Fx::actAsStaff(Fx::staff());
    $reader = app(GrantsReader::class);
    $reader->forStaff($staffId);
    $queries = requestGrantsRecord();

    expect($reader->forStaff(strtoupper($staffId))?->staffId)->toBe($staffId)
        ->and(requestGrantsReads($queries))->toBe(0);
});

it('forgets a change however the id is written', function (bool $upperOnRefresh) {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
    Fx::actAsStaff(Fx::staff());
    $reader = app(GrantsReader::class);
    $reader->forStaff($upperOnRefresh ? $staffId : strtoupper($staffId));

    Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE]), ['sa', 'eg']);
    $reader->refresh($upperOnRefresh ? strtoupper($staffId) : $staffId);

    expect($reader->forStaff($upperOnRefresh ? $staffId : strtoupper($staffId))?->stores?->storeIds())->toHaveCount(2);
})->with([
    'refreshed in capitals' => [true],
    'asked in capitals' => [false],
]);
