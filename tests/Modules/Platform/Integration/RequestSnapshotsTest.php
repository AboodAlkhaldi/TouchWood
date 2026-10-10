<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Settings\SettingValues;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The settings and the store directory, each one snapshot in the cache table, read once per web
| request (§9.11; owner, 2026-10-08). The panel's frame asked the settings three times and the stores
| twice, 2 queries each. Never for the system; never after the request itself changed them.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * How many times the cache table is read from now on, under this cache name.
 *
 * @return ArrayObject<int, string>
 */
function requestSnapshotsReads(string $name): ArrayObject
{
    /** @var ArrayObject<int, string> $reads */
    $reads = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($reads, $name): void {
        if (str_starts_with($query->sql, 'select') && str_contains(implode(' ', array_map(strval(...), $query->bindings)), $name)) {
            $reads[] = $query->sql;
        }
    });

    return $reads;
}

function requestSnapshotsGuest(): void
{
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
}

it('is one of each per request, never one for the process', function () {
    $values = app(SettingValues::class);
    $directory = app(StoreDirectory::class);

    expect(app(SettingValues::class))->toBe($values)
        ->and(app(StoreDirectory::class))->toBe($directory);

    app()->forgetScopedInstances();

    expect(app(SettingValues::class))->not->toBe($values)
        ->and(app(StoreDirectory::class))->not->toBe($directory);
});

it('reads the settings once in a web request, however many are asked for', function () {
    requestSnapshotsGuest();
    app(SettingValues::class)->find('access.staff.session_idle_minutes', null);
    app()->forgetScopedInstances();

    $reads = requestSnapshotsReads('platform:settings');
    $values = app(SettingValues::class);

    foreach (range(1, 5) as $ignored) {
        $values->find('access.staff.session_idle_minutes', null);
        $values->find('access.staff.session_max_hours', null);
        $values->find('anything.at.all', Fx::storeId('sa'));
    }

    expect($reads)->toHaveCount(2);
});

it('reads the stores once in a web request, however they are asked for', function () {
    requestSnapshotsGuest();
    app(StoreDirectory::class)->stores();
    app()->forgetScopedInstances();

    $reads = requestSnapshotsReads('platform:store-directory');
    $directory = app(StoreDirectory::class);

    foreach (range(1, 5) as $ignored) {
        $directory->stores();
        $directory->storeByCode('sa');
        $directory->storeById(Fx::storeId('eg'));
        $directory->currencies();
    }

    expect($reads)->toHaveCount(2);
});

it('never answers the system from memory', function () {
    // Tests run in the console: the system is acting.
    expect(app(ActorContext::class)->current()->type)->toBe(ActorType::System);

    $settings = requestSnapshotsReads('platform:settings');
    $stores = requestSnapshotsReads('platform:store-directory');

    foreach (range(1, 3) as $ignored) {
        app(SettingValues::class)->find('access.staff.session_idle_minutes', null);
        app(StoreDirectory::class)->stores();
    }

    expect($settings)->toHaveCount(6)
        ->and($stores)->toHaveCount(6);
});

it('answers a request with the setting it first read, until the request itself changes it', function () {
    requestSnapshotsGuest();
    $values = app(SettingValues::class);
    $before = $values->find('access.staff.session_idle_minutes', null)?->value;

    // Another request changes it: this one keeps what it read, the next one sees the change.
    app()->forgetScopedInstances();
    $other = app(SettingValues::class);
    $other->save('access.staff.session_idle_minutes', null, 45, null);
    $other->invalidate();

    app()->forgetScopedInstances();

    expect($values->find('access.staff.session_idle_minutes', null)?->value)->toBe($before)
        ->and(app(SettingValues::class))->not->toBe($other)
        ->and(app(SettingValues::class)->find('access.staff.session_idle_minutes', null)?->value)->toBe(45);

    // A change in this request forgets it, and from then on it is read from the cache.
    $values->save('access.staff.session_idle_minutes', null, 50, null);
    $values->invalidate();
    $reads = requestSnapshotsReads('platform:settings');

    expect($values->find('access.staff.session_idle_minutes', null)?->value)->toBe(50);
    $values->find('access.staff.session_idle_minutes', null);

    expect($reads)->toHaveCount(4);
});

it('reads the stores from the cache again once the request itself changed them', function () {
    requestSnapshotsGuest();
    $directory = app(StoreDirectory::class);
    expect($directory->storeByCode('eg')?->timezone)->toBe('Africa/Cairo');

    DB::table('platform.stores')->where('code', 'eg')->update(['timezone' => 'Africa/Tripoli']);
    $directory->invalidate();
    $reads = requestSnapshotsReads('platform:store-directory');

    expect($directory->storeByCode('eg')?->timezone)->toBe('Africa/Tripoli');
    $directory->stores();

    expect($reads)->toHaveCount(4);
});

it('reads through the cache inside a transaction opened after the request began, and keeps nothing it read there', function () {
    requestSnapshotsGuest();
    $values = app(SettingValues::class);
    $directory = app(StoreDirectory::class);
    $before = $values->find('access.staff.session_idle_minutes', null)?->value;
    expect($directory->storeByCode('eg')?->isActive)->toBeTrue();

    // Another request changes both.
    app()->forgetScopedInstances();
    $other = app(SettingValues::class);
    $other->save('access.staff.session_idle_minutes', null, 45, null);
    $other->invalidate();
    DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => false]);
    app(StoreDirectory::class)->invalidate();

    DB::transaction(function () use ($values, $directory): void {
        expect($values->find('access.staff.session_idle_minutes', null)?->value)->toBe(45)
            ->and($directory->storeByCode('eg')?->isActive)->toBeFalse();
    });

    expect($values->find('access.staff.session_idle_minutes', null)?->value)->toBe($before)
        ->and($directory->storeByCode('eg')?->isActive)->toBeTrue();
});
