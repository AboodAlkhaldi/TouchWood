<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Public\PlatformPermissions;

uses(RefreshDatabase::class);

/**
 * The PERMISSION constant of every command handler in every module.
 *
 * @return array<string, string> handler class => permission
 */
function handlerPermissions(): array
{
    $permissions = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/*/Application/Command/*/*Handler.php') ?: [] as $file) {
        $module = basename(dirname($file, 4));
        $command = basename(dirname($file));
        $class = "Modules\\{$module}\\Application\\Command\\{$command}\\".basename($file, '.php');
        $constant = "{$class}::PERMISSION";

        if (defined($constant)) {
            $permissions[$class] = (string) constant($constant);
        }
    }

    return $permissions;
}

it('declares every Platform permission, with the reserved ones reserved and the store-free ones store-free', function () {
    $catalog = app(InMemoryPermissionCatalog::class);

    foreach (PlatformPermissions::all() as $name => $permission) {
        expect($catalog->definition($name)?->reserved)->toBe($permission['reserved'], $name)
            ->and($catalog->definition($name)?->kind)->toBe($permission['storeFree'] ? PermissionKind::Global : PermissionKind::PerStore, $name);
    }
});

it('sorts the permissions into store-free and per-store as the owner approved', function () {
    $storeFree = array_values(array_map(
        fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), fn (PermissionDefinitionDto $permission): bool => $permission->audience === PermissionAudience::Role && $permission->kind === PermissionKind::Global),
    ));
    sort($storeFree);

    // Owner's decision, 2026-09-19 (Access spec §9.4, amendment 4).
    expect($storeFree)->toBe([
        'access.account.anonymize',
        'access.role.manage',
        'access.super_admin.manage',
        'platform.currency.create',
        'platform.currency.update',
        'platform.media.delete',
        'platform.media.update',
        'platform.media.upload',
        'platform.media.variants.generate',
        'platform.store.create',
    ]);
});

it('keeps the management actions out of staff roles, and viewing staff in them', function () {
    expect(AccessPermissions::adminOnly())->toBe([
        AccessPermissions::STAFF_INVITE,
        AccessPermissions::STAFF_UPDATE,
        AccessPermissions::STAFF_ASSIGN_ROLE,
        AccessPermissions::STAFF_DISABLE,
        AccessPermissions::ROLE_MANAGE,
    ]);
    expect(AccessPermissions::adminOnly())->not->toContain(AccessPermissions::STAFF_VIEW);
});

it('checks the declared renames and removals once every module has booted', function () {
    // verify() ran when the application booted; with nothing renamed it passes.
    expect(app(InMemoryPermissionCatalog::class)->renames())->toBe([])
        ->and(app(InMemoryPermissionCatalog::class)->removals())->toBe([]);
});

it('declares every Access permission', function () {
    $catalog = app(InMemoryPermissionCatalog::class);

    foreach (AccessPermissions::definitions() as $permission) {
        expect($catalog->definition($permission->name))->toEqual($permission);
    }
});

it('declares the permission of every command handler', function () {
    $permissions = handlerPermissions();
    $catalog = app(InMemoryPermissionCatalog::class);

    // A moved folder must not make this pass over nothing.
    expect($permissions)->not->toBeEmpty();

    foreach ($permissions as $handler => $permission) {
        expect($catalog->definition($permission))->not->toBeNull("{$handler} checks \"{$permission}\", which no module declares");
    }
});

it('declares the permission of every setting, as a per-store one: a global setting is checked against every store', function () {
    $settings = app(InMemorySettingsRegistry::class)->all();
    $catalog = app(InMemoryPermissionCatalog::class);

    // A moved folder or a renamed provider must not make this pass over nothing.
    expect($settings)->not->toBeEmpty();

    foreach ($settings as $setting) {
        expect($catalog->definition($setting->permission)?->kind)->toBe(PermissionKind::PerStore, "{$setting->key} is changed under \"{$setting->permission}\"");
    }
});

it('names every permission in Arabic and English', function () {
    $permissions = app(InMemoryPermissionCatalog::class)->all();

    expect($permissions)->not->toBeEmpty();

    foreach ($permissions as $permission) {
        foreach (['ar', 'en'] as $locale) {
            $label = trans($permission->labelKey(), [], $locale);

            expect(is_string($label) && $label !== '' && $label !== $permission->labelKey())
                ->toBeTrue("{$permission->name} has no {$locale} name at {$permission->labelKey()}");
        }
    }
});

it('offers no reserved or automatic permission in the role editor', function () {
    $offered = array_map(fn (PermissionDefinitionDto $permission): string => $permission->name, app(InMemoryPermissionCatalog::class)->assignable());

    expect($offered)->toContain(PlatformPermissions::STORE_UPDATE, AccessPermissions::STAFF_INVITE);
    expect($offered)->not->toContain(PlatformPermissions::STORE_CREATE, AccessPermissions::SUPER_ADMIN_MANAGE, AccessPermissions::ACCOUNT_REGISTER);
});

it('creates the access schema, on the search path so a fresh migration wipes it', function () {
    expect(DB::table('information_schema.schemata')->where('schema_name', 'access')->exists())->toBeTrue()
        ->and(explode(',', (string) config('database.connections.pgsql.search_path')))->toContain('access');
});
