<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Permission\InvalidPermissionDefinition;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
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

/**
 * The command handlers that declare no PERMISSION constant.
 *
 * @return list<string>
 */
function handlersWithoutPermissionConstant(): array
{
    $missing = [];

    foreach (glob(dirname(__DIR__, 4).'/src/Modules/*/Application/Command/*/*Handler.php') ?: [] as $file) {
        $module = basename(dirname($file, 4));
        $command = basename(dirname($file));
        $class = "Modules\\{$module}\\Application\\Command\\{$command}\\".basename($file, '.php');

        if (! defined("{$class}::PERMISSION")) {
            $missing[] = $class;
        }
    }

    sort($missing);

    return $missing;
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
        // Reserved: the import reaches every store at once, and a job belongs to none (catalog.md §3).
        'catalog.import.run',
        'catalog.listing.rebuild',
        'catalog.search_log.prune',
        // Reserved: the system's nightly expiry of points (loyalty.md §3).
        'loyalty.points.expire',
        'platform.currency.create',
        // Reserved, as creating one is (platform.md §9.7, owner 2026-10-04).
        'platform.currency.delete',
        'platform.currency.update',
        // Admin-only; a job belongs to no store (owner, 2026-09-29).
        'platform.jobs.manage',
        'platform.media.delete',
        // Admin-only, and store-free like every media action (B2B step 3, amendment 5).
        'platform.media.private.view',
        'platform.media.update',
        'platform.media.upload',
        'platform.media.variants.generate',
        'platform.store.create',
        // Reserved, and about whether a store is a place to work in at all (owner, 2026-10-01).
        'platform.store.switch',
        // Reserved: the system's jobs belong to no store (pricing.md §3).
        'pricing.candidates.rebuild',
        'pricing.windows.apply',
        'pricing.windows.check',
    ]);
});

it('keeps the management actions out of staff roles, and viewing staff in them', function () {
    expect(AccessPermissions::adminOnly())->toBe([
        AccessPermissions::STAFF_INVITE,
        AccessPermissions::STAFF_UPDATE,
        AccessPermissions::STAFF_ASSIGN_ROLE,
        AccessPermissions::STAFF_DISABLE,
        AccessPermissions::ROLE_MANAGE,
        // Blocking and deleting a customer reach a person's account (amendment 43).
        AccessPermissions::CUSTOMER_BLOCK,
        AccessPermissions::CUSTOMER_DELETE,
        // The staff security settings decide how everyone signs in; a store's own settings do not
        // (owner, 2026-09-21).
        AccessPermissions::STAFF_SETTINGS_UPDATE,
        // Platform's own, flagged in PlatformPermissions: private files in the media library
        // (B2B step 3, amendment 5), and the failed jobs, whose errors can hold personal data
        // (owner, 2026-09-29).
        PlatformPermissions::MEDIA_PRIVATE_VIEW,
        PlatformPermissions::JOBS_MANAGE,
    ]);
    expect(AccessPermissions::adminOnly())->not->toContain(AccessPermissions::SETTINGS_UPDATE);
    expect(AccessPermissions::adminOnly())->not->toContain(AccessPermissions::STAFF_VIEW);
});

it('lets a module mark a permission a role can hold admin-only, held to the management actions\' rule (Catalog amendment 6(h))', function () {
    $catalog = new InMemoryPermissionCatalog;
    $catalog->declare('demo', new PermissionDefinitionDto('demo.report.fill', group: PermissionGroup::Catalog, adminOnly: true));
    $catalog->declare('demo', new PermissionDefinitionDto('demo.report.view', group: PermissionGroup::Catalog));

    expect($catalog->isAdminOnly('demo.report.fill'))->toBeTrue()
        ->and($catalog->isAdminOnly('demo.report.view'))->toBeFalse()
        ->and($catalog->isAdminOnly('demo.report.unknown'))->toBeFalse()
        // Access's own and Platform's stay admin-only through the same question.
        ->and($catalog->isAdminOnly(AccessPermissions::STAFF_INVITE))->toBeTrue()
        ->and($catalog->isAdminOnly(PlatformPermissions::JOBS_MANAGE))->toBeTrue()
        ->and(fn () => $catalog->declare('demo', new PermissionDefinitionDto('demo.report.run', reserved: true, kind: PermissionKind::Global, adminOnly: true)))
        ->toThrow(InvalidPermissionDefinition::class, 'cannot be admin-only')
        ->and(fn () => $catalog->declare('demo', new PermissionDefinitionDto('demo.report.own', PermissionAudience::EveryStaff, adminOnly: true)))
        ->toThrow(InvalidPermissionDefinition::class, 'cannot be admin-only');
});

it('passes its own check of the renames and removals every module declared', function () {
    $catalog = app(InMemoryPermissionCatalog::class);

    // verify() runs when the application boots (AccessServiceProvider). Running it again
    // here against the real declarations is what proves they are sound: a rename to a name
    // no module declares, or one both renamed and removed, throws (review of step 7).
    expect($catalog->all())->not->toBeEmpty();
    $catalog->verify();

    expect($catalog->renames())->toBe([])
        ->and($catalog->removals())->toBe([]);
});

it('gives every command handler a PERMISSION constant, save the one that reads it from the setting', function () {
    // UpdateSetting takes its permission from the setting's own definition, so it declares
    // none; every other handler must, or handlerPermissions() would pass over it.
    expect(handlersWithoutPermissionConstant())->toBe(['Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler']);
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

it('gives every action a role can hold a business area, and names every area in both languages', function () {
    $catalog = app(InMemoryPermissionCatalog::class);
    $offered = $catalog->assignable();
    $used = [];

    // A moved folder or a renamed provider must not make this pass over nothing.
    expect(count($offered))->toBeGreaterThan(15);

    $without = [];

    foreach ($offered as $permission) {
        $group = $permission->group;

        if ($group === null) {
            $without[] = $permission->name;

            continue;
        }

        $used[$group->value] = true;
    }

    expect($without)->toBe([]);

    // Every area in the list is named, not only the ones in use: a module built later picks one.
    foreach (PermissionGroup::cases() as $group) {
        foreach (['ar', 'en'] as $locale) {
            $label = trans($group->labelKey(), [], $locale);

            expect(is_string($label) && $label !== '' && $label !== $group->labelKey())
                ->toBeTrue("{$group->value} has no {$locale} name at {$group->labelKey()}");
        }
    }

    // The areas the modules actually use today (owner, 2026-09-19 and 2026-09-22); B2B's staff jobs
    // fill Companies (b2b.md amendment 10), the failed jobs System (owner, 2026-09-29), Catalog's
    // jobs Catalog (catalog.md §3), Pricing's jobs Pricing (pricing.md §3), Loyalty's jobs Points
    // (loyalty.md §3, amendment 66).
    ksort($used);
    expect(array_keys($used))->toBe(['audit', 'catalog', 'companies', 'customers', 'media', 'points', 'pricing', 'staff_and_permissions', 'store_settings', 'system']);
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
