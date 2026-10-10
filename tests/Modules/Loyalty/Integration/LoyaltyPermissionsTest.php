<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Query\RoleEditorPermissions\EditorPermission;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissions;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Domain\Exception\AdminOnlyPermission;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Loyalty's permissions in Access's catalog (loyalty.md §3): a customer's own points, three jobs a
| role may hold — per store, in the Points group, two of them admin-only (owner, 2026-10-07) — and the
| nightly expiry, reserved to the system. Their names in both languages are checked with every
| module's by Access's own test.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string> the names Loyalty declared, sorted
 */
function loyaltyDeclaredPermissions(): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'loyalty.')),
    ));
    sort($names);

    return $names;
}

/** Whether the role editor offers the permission for a role of that level, and how. */
function loyaltyOffered(string $name, RoleLevel $level): ?EditorPermission
{
    foreach (app(RoleEditorPermissionsHandler::class)->handle(new RoleEditorPermissions($level)) as $item) {
        if ($item->name === $name) {
            return $item;
        }
    }

    return null;
}

it('declares the five permissions of the spec, and nothing else', function () {
    expect(loyaltyDeclaredPermissions())->toBe([
        'loyalty.points.adjust',
        'loyalty.points.expire',
        'loyalty.points.view',
        'loyalty.points.view_own',
        'loyalty.settings.update',
    ]);
});

it('gives every customer their own points, store-free, outside the role editor', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(LoyaltyPermissions::VIEW_OWN);

    expect($permission?->audience)->toBe(PermissionAudience::EveryCustomer)
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->reserved)->toBeFalse()
        ->and($permission?->group)->toBeNull();

    Fx::actAsCustomer(Fx::customer());

    expect(Fx::allows(LoyaltyPermissions::VIEW_OWN, PermissionScope::global()))->toBeTrue();
});

it('makes the three jobs per store, offered under Points, and never reserved', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->audience)->toBe(PermissionAudience::Role)
        ->and($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::Points)
        ->and($permission?->reserved)->toBeFalse()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->toContain($name);
})->with(LoyaltyPermissions::jobs());

it('keeps viewing points open to staff roles, and changing them to admin roles alone', function () {
    expect(LoyaltyPermissions::adminOnly())->toBe([LoyaltyPermissions::ADJUST, LoyaltyPermissions::SETTINGS_UPDATE]);

    // Creating the role goes through Access's own rules, which refuse an admin-only action in a staff
    // role — so a staff role holding the view is the proof that it is open.
    $roleId = Fx::role([LoyaltyPermissions::VIEW], RoleLevel::Staff);

    expect(Fx::rolePermissions($roleId))->toBe([LoyaltyPermissions::VIEW])
        ->and(app(InMemoryPermissionCatalog::class)->isAdminOnly(LoyaltyPermissions::VIEW))->toBeFalse();
});

describe('changing points by hand and the programme, for admin roles only (owner, 2026-10-07)', function () {
    it('goes into an admin role and never into a staff role', function (string $name) {
        expect(app(InMemoryPermissionCatalog::class)->isAdminOnly($name))->toBeTrue()
            ->and(app(InMemoryPermissionCatalog::class)->definition($name)?->adminOnly)->toBeTrue()
            ->and(fn () => Fx::role([$name], RoleLevel::Staff))->toThrow(AdminOnlyPermission::class);

        $roleId = Fx::role([$name], RoleLevel::Admin);

        expect(Fx::rolePermissions($roleId))->toBe([$name]);
    })->with(LoyaltyPermissions::adminOnly());

    it('is offered for an admin role, marked admin-only, and not for a staff role', function (string $name) {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(loyaltyOffered($name, RoleLevel::Admin)?->adminOnly)->toBeTrue()
            ->and(loyaltyOffered($name, RoleLevel::Staff))->toBeNull()
            // A control: the staff list is not simply empty — the open job is offered there.
            ->and(loyaltyOffered(LoyaltyPermissions::VIEW, RoleLevel::Staff)?->adminOnly)->toBeFalse();
    })->with(LoyaltyPermissions::adminOnly());

    it('grants nothing through a staff role that came to hold it another way, and is granted through an admin role', function (string $name) {
        $staffId = Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']);
        $roleId = Fx::roleOf($staffId);
        DB::table('access.role_permissions')->insert(['role_id' => $roleId, 'permission' => $name]);
        app(GrantsReader::class)->refresh($staffId);
        Fx::actAsStaff($staffId);
        $sa = PermissionScope::store(StoreId::fromString(Fx::storeId('sa')));

        // The row is really there, and the role's own job still works: only the admin-only one fails.
        expect(Fx::rolePermissions($roleId))->toContain($name)
            ->and(Fx::allows(LoyaltyPermissions::VIEW, $sa))->toBeTrue()
            ->and(fn () => app(Authorizer::class)->authorize($name, $sa))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith([$name], ['sa'], RoleLevel::Admin));
        app(Authorizer::class)->authorize($name, $sa);

        expect(app(Authorizer::class)->storesWith($name))->toEqual([StoreId::fromString(Fx::storeId('sa'))]);
    })->with(LoyaltyPermissions::adminOnly());
});

it('reserves the nightly expiry to the system, store-free and never offered', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(LoyaltyPermissions::EXPIRE);

    expect(LoyaltyPermissions::reserved())->toBe([LoyaltyPermissions::EXPIRE])
        ->and($permission?->reserved)->toBeTrue()
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->not->toContain(LoyaltyPermissions::EXPIRE);
});
