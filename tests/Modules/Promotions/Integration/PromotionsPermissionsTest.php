<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Query\RoleEditorPermissions\EditorPermission;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissions;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Promotions\Application\PromotionsPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Promotions' permissions in Access's catalog (promotions.md §3): four jobs a role may hold — per
| store, under Marketing, none admin-only (owner, 2026-10-08) — a customer's own coupons, and the
| nightly refresh of segments, reserved to the system. No setting of its own: the ceiling is Pricing's
| (owner, 2026-10-17). Their names in both languages are checked with every module's by Access's test.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string> the names Promotions declared, sorted
 */
function promotionsDeclaredPermissions(): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'promotions.')),
    ));
    sort($names);

    return $names;
}

/** Whether the role editor offers the permission for a role of that level, and how. */
function promotionsOffered(string $name, RoleLevel $level): ?EditorPermission
{
    foreach (app(RoleEditorPermissionsHandler::class)->handle(new RoleEditorPermissions($level)) as $item) {
        if ($item->name === $name) {
            return $item;
        }
    }

    return null;
}

it('declares the six permissions of the spec, and no setting of its own', function () {
    expect(promotionsDeclaredPermissions())->toBe([
        'promotions.coupon.manage',
        'promotions.coupon.view_own',
        'promotions.gift.manage',
        'promotions.marketing.view',
        'promotions.segment.manage',
        'promotions.segment.refresh',
    ]);
});

it('gives every customer their own coupons, store-free, outside the role editor', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(PromotionsPermissions::VIEW_OWN);

    expect($permission?->audience)->toBe(PermissionAudience::EveryCustomer)
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->reserved)->toBeFalse()
        ->and($permission?->group)->toBeNull();

    Fx::actAsCustomer(Fx::customer());

    expect(Fx::allows(PromotionsPermissions::VIEW_OWN, PermissionScope::global()))->toBeTrue();
});

it('makes the four jobs per store, offered under Marketing, never reserved', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->audience)->toBe(PermissionAudience::Role)
        ->and($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::Marketing)
        ->and($permission?->reserved)->toBeFalse()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->toContain($name);
})->with(PromotionsPermissions::jobs());

describe('the four jobs, open to a staff role (owner, 2026-10-08)', function () {
    it('goes into a staff role and is offered there, not marked admin-only', function (string $name) {
        expect(app(InMemoryPermissionCatalog::class)->isAdminOnly($name))->toBeFalse();

        $roleId = Fx::role([$name], RoleLevel::Staff);
        expect(Fx::rolePermissions($roleId))->toBe([$name]);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(promotionsOffered($name, RoleLevel::Staff)?->adminOnly)->toBeFalse();
    })->with(PromotionsPermissions::jobs());

    it('is granted in the role\'s own store only', function (string $name) {
        Fx::actAsStaff(Fx::staffWith([$name], ['sa']));

        expect(Fx::allows($name, PermissionScope::store(StoreId::fromString(Fx::storeId('sa')))))->toBeTrue()
            ->and(Fx::allows($name, PermissionScope::store(StoreId::fromString(Fx::storeId('ae')))))->toBeFalse()
            ->and(app(Authorizer::class)->storesWith($name))->toEqual([StoreId::fromString(Fx::storeId('sa'))]);
    })->with(PromotionsPermissions::jobs());
});

it('reserves the nightly refresh to the system, store-free and never offered', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(PromotionsPermissions::SEGMENT_REFRESH);

    expect($permission?->reserved)->toBeTrue()
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->not->toContain(PromotionsPermissions::SEGMENT_REFRESH);
});
