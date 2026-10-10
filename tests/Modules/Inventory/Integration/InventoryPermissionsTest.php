<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\InventorySettings;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Inventory's permission in Access's catalog (inventory.md §3): one job - Manage Stock, per store, any
| role, in the Catalog group (owner, 2026-10-09) - and the system's two, reserved; and the store's
| default low-stock threshold in Platform's settings (§1.2): 10 pieces, changed with Manage Stock.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string> the names Inventory declared with that reservation, sorted
 */
function inventoryDeclaredPermissions(bool $reserved): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'inventory.') && $permission->reserved === $reserved),
    ));
    sort($names);

    return $names;
}

// Two literal lists, split by the reservation, so a name moved from one list to the other is caught.
it('declares the one job of the spec as a job, and nothing else', function () {
    expect(inventoryDeclaredPermissions(reserved: false))->toBe(['inventory.stock.manage']);
});

it('reserves exactly the two system jobs, and nothing else', function () {
    expect(inventoryDeclaredPermissions(reserved: true))->toBe([
        'inventory.holds.expire',
        'inventory.orderable.rebuild',
    ]);
});

it('makes Manage Stock per store, offered in the role editor under Catalog, never reserved, open to any role', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(InventoryPermissions::STOCK_MANAGE);

    expect($permission?->audience)->toBe(PermissionAudience::Role)
        ->and($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::Catalog)
        ->and($permission?->reserved)->toBeFalse()
        ->and($permission?->adminOnly)->toBeFalse()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->toContain(InventoryPermissions::STOCK_MANAGE);

    // Creating the role goes through Access's own rules, which refuse an admin-only action in a staff
    // role - so a staff role holding it is the proof.
    $roleId = Fx::role(InventoryPermissions::jobs(), RoleLevel::Staff);

    expect(Fx::rolePermissions($roleId))->toBe([InventoryPermissions::STOCK_MANAGE]);
});

it('calls it by the name the owner gave it', function () {
    expect(trans('inventory::permissions.stock.manage', [], 'en'))->toBe('Manage Stock');
});

it('reserves the system jobs, store-free and never offered', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->reserved)->toBeTrue()
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->not->toContain($name);
})->with(InventoryPermissions::reserved());

it('declares the store\'s default low-stock threshold: per store, a whole number from 0, 10 unless set, changed with Manage Stock', function () {
    $setting = app(InMemorySettingsRegistry::class)->definition(InventorySettings::LOW_STOCK_DEFAULT);

    expect($setting?->scope)->toBe(SettingScope::Store)
        ->and($setting?->type)->toBe(SettingType::Integer)
        ->and($setting?->default)->toBe(10)
        ->and($setting?->permission)->toBe(InventoryPermissions::STOCK_MANAGE)
        ->and($setting?->bounds())->toBe(['min' => 0, 'max' => 100000])
        ->and(trans('inventory::settings.low_stock.default', [], 'en'))->toBe('Default Low-Stock Threshold')
        ->and(trans('inventory::settings.low_stock.default', [], 'ar'))->not->toBe('inventory::settings.low_stock.default');
});
