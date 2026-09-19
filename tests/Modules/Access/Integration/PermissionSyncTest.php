<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Infrastructure\Permission\PermissionSync;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * A staff member holding a role with these actions, written straight into the tables: the old
 * names are no longer declared, so no handler would accept them.
 *
 * @param  list<string>  $permissions
 * @param  array<string, list<string>>  $exceptions  permission => store codes
 * @return array{staff: string, role: string}
 */
function holderOfOldNames(array $permissions, array $exceptions = []): array
{
    $staffId = Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa', 'ae']);
    $roleId = (string) DB::table('access.role_assignments')->where('staff_user_id', $staffId)->value('role_id');

    DB::table('access.role_permissions')->where('role_id', $roleId)->delete();
    DB::table('access.role_permissions')->insert(array_map(fn (string $permission): array => ['role_id' => $roleId, 'permission' => $permission], $permissions));

    foreach ($exceptions as $permission => $codes) {
        DB::table('access.role_assignment_exceptions')->insert(['staff_user_id' => $staffId, 'permission' => $permission, 'access_level' => 'SELECTED_STORES']);
        DB::table('access.role_assignment_exception_stores')->insert(array_map(
            fn (string $code): array => ['staff_user_id' => $staffId, 'permission' => $permission, 'store_id' => Fx::storeId($code)],
            $codes,
        ));
    }

    app(GrantsReader::class)->refresh($staffId);

    return ['staff' => $staffId, 'role' => $roleId];
}

/**
 * @return list<string>
 */
function permissionsOfRole(string $roleId): array
{
    /** @var list<string> */
    return DB::table('access.role_permissions')->where('role_id', $roleId)->orderBy('permission')->pluck('permission')->all();
}

/**
 * @return array<string, list<string>> permission => store ids
 */
function exceptionsOf(string $staffId): array
{
    $exceptions = [];

    foreach (DB::table('access.role_assignment_exceptions')->where('staff_user_id', $staffId)->orderBy('permission')->pluck('permission') as $permission) {
        /** @var list<string> $stores */
        $stores = DB::table('access.role_assignment_exception_stores')->where('staff_user_id', $staffId)->where('permission', $permission)->orderBy('store_id')->pluck('store_id')->all();
        $exceptions[(string) $permission] = $stores;
    }

    return $exceptions;
}

it('carries a renamed permission into every role and exception, with its stores', function () {
    $holder = holderOfOldNames(['platform.store.edit', 'platform.media.upload'], ['platform.store.edit' => ['sa']]);
    app(InMemoryPermissionCatalog::class)->renamed('platform', 'platform.store.edit', PlatformPermissions::STORE_UPDATE);

    event(new MigrationsEnded('up'));

    expect(permissionsOfRole($holder['role']))->toBe([PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::STORE_UPDATE])
        ->and(exceptionsOf($holder['staff']))->toBe([PlatformPermissions::STORE_UPDATE => [Fx::storeId('sa')]])
        ->and(DB::table('platform.audit_entries')->where('action', 'access.role.permissions_synced')->where('subject_id', $holder['role'])->exists())->toBeTrue()
        ->and(DB::table('platform.audit_entries')->where('action', 'access.staff_user.exceptions_synced')->where('subject_id', $holder['staff'])->exists())->toBeTrue()
        // The cached permissions were rebuilt with the change.
        ->and(app(GrantsReader::class)->forStaff($holder['staff'])?->storesFor(PlatformPermissions::STORE_UPDATE)?->storeIds())->toBe([Fx::storeId('sa')]);
});

it('merges a renamed permission into the new name when the role holds both', function () {
    $holder = holderOfOldNames(['platform.store.edit', PlatformPermissions::STORE_UPDATE], ['platform.store.edit' => ['sa'], PlatformPermissions::STORE_UPDATE => ['ae']]);
    app(InMemoryPermissionCatalog::class)->renamed('platform', 'platform.store.edit', PlatformPermissions::STORE_UPDATE);

    event(new MigrationsEnded('up'));

    expect(permissionsOfRole($holder['role']))->toBe([PlatformPermissions::STORE_UPDATE])
        // The new name's own stores win.
        ->and(exceptionsOf($holder['staff']))->toBe([PlatformPermissions::STORE_UPDATE => [Fx::storeId('ae')]]);
});

it('drops an exception when the new name is store-free', function () {
    $holder = holderOfOldNames(['platform.media.add'], ['platform.media.add' => ['sa']]);
    app(InMemoryPermissionCatalog::class)->renamed('platform', 'platform.media.add', PlatformPermissions::MEDIA_UPLOAD);

    event(new MigrationsEnded('up'));

    expect(permissionsOfRole($holder['role']))->toBe([PlatformPermissions::MEDIA_UPLOAD])
        ->and(exceptionsOf($holder['staff']))->toBe([]);
});

it('takes a removed permission out of every role and exception', function () {
    $holder = holderOfOldNames(['platform.store.archive', PlatformPermissions::STORE_UPDATE], ['platform.store.archive' => ['sa']]);
    app(InMemoryPermissionCatalog::class)->removed('platform', 'platform.store.archive');

    event(new MigrationsEnded('up'));

    expect(permissionsOfRole($holder['role']))->toBe([PlatformPermissions::STORE_UPDATE])
        ->and(exceptionsOf($holder['staff']))->toBe([]);
});

it('leaves a name nobody declares or removes alone, and reports it', function () {
    $holder = holderOfOldNames(['catalog.product.update', PlatformPermissions::STORE_UPDATE]);

    $result = app(PermissionSync::class)->run();

    expect($result['unknown'])->toBe(['catalog.product.update'])
        ->and(permissionsOfRole($holder['role']))->toBe(['catalog.product.update', PlatformPermissions::STORE_UPDATE]);
});

it('runs at the end of php artisan migrate, also with nothing to migrate', function () {
    $holder = holderOfOldNames(['platform.store.edit']);
    app(InMemoryPermissionCatalog::class)->renamed('platform', 'platform.store.edit', PlatformPermissions::STORE_UPDATE);

    $migrate = artisan('migrate');

    if (! $migrate instanceof PendingCommand) {
        throw new LogicException('Console output mocking is off, so the command result cannot be asserted.');
    }

    $migrate->assertSuccessful()->run();

    expect(permissionsOfRole($holder['role']))->toBe([PlatformPermissions::STORE_UPDATE]);
});

it('does nothing on a rollback, or on a pretend run with migrations pending', function (object $event) {
    $holder = holderOfOldNames(['platform.store.edit']);
    app(InMemoryPermissionCatalog::class)->renamed('platform', 'platform.store.edit', PlatformPermissions::STORE_UPDATE);

    event($event);

    expect(permissionsOfRole($holder['role']))->toBe(['platform.store.edit']);
})->with([
    'rolled back' => [fn () => new MigrationsEnded('down')],
    'nothing to roll back' => [fn () => new NoPendingMigrations('down')],
    'pretend' => [fn () => new MigrationsEnded('up', ['pretend' => true])],
]);
