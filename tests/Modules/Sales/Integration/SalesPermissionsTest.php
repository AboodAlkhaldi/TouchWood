<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Sales\Application\SalesPermissions;
use Shared\Application\Actor;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Sales's permissions in Access's catalog (sales.md §3): what every guest and every customer may do in
| a store's shop, checked in the cart's or the order's store; the staff jobs per store under Orders;
| the money settings admin-only; the system's scheduled work reserved. Their names in both languages
| are checked with every module's by Access's test.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function salesStore(string $code): PermissionScope
{
    return PermissionScope::store(StoreId::fromString(Fx::storeId($code)));
}

it('declares the fourteen permissions of the spec', function () {
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'sales.')),
    ));
    sort($names);

    expect($names)->toBe([
        'sales.cart.manage', 'sales.guest_cart.manage', 'sales.order.approve', 'sales.order.cancel', 'sales.order.edit', 'sales.order.fulfil',
        'sales.order.manage_own', 'sales.order.place', 'sales.order.view', 'sales.order.view_own', 'sales.payment.record', 'sales.return.manage',
        'sales.settings.update', 'sales.system.run',
    ]);
});

it('gives every customer their cart and orders, in any store, outside the role editor', function (string $name, PermissionKind $kind) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect([$permission?->audience, $permission?->kind, $permission?->group])->toBe([PermissionAudience::EveryCustomer, $kind, null]);

    Fx::actAsCustomer(Fx::customer());

    expect(Fx::allows($name, $kind === PermissionKind::Global ? PermissionScope::global() : salesStore('ae')))->toBeTrue();
})->with([
    'cart' => [SalesPermissions::CART_MANAGE, PermissionKind::PerStore],
    'place' => [SalesPermissions::ORDER_PLACE, PermissionKind::PerStore],
    'own orders' => [SalesPermissions::ORDER_MANAGE_OWN, PermissionKind::PerStore],
    'see own' => [SalesPermissions::ORDER_VIEW_OWN, PermissionKind::Global],
]);

it('gives every guest a cart in any store, and nothing a customer has', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(SalesPermissions::GUEST_CART_MANAGE);

    expect([$permission?->audience, $permission?->kind, $permission?->group])->toBe([PermissionAudience::EveryGuest, PermissionKind::PerStore, null]);

    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

    expect(Fx::allows(SalesPermissions::GUEST_CART_MANAGE, salesStore('sa')))->toBeTrue()
        ->and(Fx::allows(SalesPermissions::CART_MANAGE, salesStore('sa')))->toBeFalse()
        ->and(Fx::allows(SalesPermissions::ORDER_PLACE, salesStore('sa')))->toBeFalse();
});

it('makes the staff jobs per store, under Orders, in the role\'s own store', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect([$permission?->audience, $permission?->kind, $permission?->group, $permission?->reserved])->toBe([PermissionAudience::Role, PermissionKind::PerStore, PermissionGroup::Orders, false])
        ->and(app(InMemoryPermissionCatalog::class)->isAdminOnly($name))->toBeFalse();

    Fx::actAsStaff(Fx::staffWith([$name], ['sa']));

    expect(Fx::allows($name, salesStore('sa')))->toBeTrue()
        ->and(Fx::allows($name, salesStore('ae')))->toBeFalse()
        ->and(app(Authorizer::class)->storesWith($name))->toEqual([StoreId::fromString(Fx::storeId('sa'))]);
})->with([
    SalesPermissions::ORDER_VIEW, SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_EDIT, SalesPermissions::ORDER_CANCEL,
    SalesPermissions::ORDER_FULFIL, SalesPermissions::PAYMENT_RECORD, SalesPermissions::RETURN_MANAGE,
]);

it('keeps the money settings to admin roles, under Orders', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(SalesPermissions::SETTINGS_UPDATE);

    expect([$permission?->kind, $permission?->group])->toBe([PermissionKind::PerStore, PermissionGroup::Orders])
        ->and(app(InMemoryPermissionCatalog::class)->isAdminOnly(SalesPermissions::SETTINGS_UPDATE))->toBeTrue();
});

it('reserves the scheduled work to the system, store-free and never offered', function () {
    $permission = app(InMemoryPermissionCatalog::class)->definition(SalesPermissions::SYSTEM_RUN);

    expect([$permission?->reserved, $permission?->kind])->toBe([true, PermissionKind::Global])
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->not->toContain(SalesPermissions::SYSTEM_RUN);
});

it('keeps the audiences apart: staff hold no customer cart, a customer no staff job', function () {
    // A staff member with every order job (a Super Admin passes every check by design).
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW, SalesPermissions::ORDER_EDIT, SalesPermissions::ORDER_FULFIL], ['*']));
    expect(Fx::allows(SalesPermissions::CART_MANAGE, salesStore('sa')))->toBeFalse()
        ->and(Fx::allows(SalesPermissions::ORDER_PLACE, salesStore('sa')))->toBeFalse();

    Fx::actAsCustomer(Fx::customer());
    expect(Fx::allows(SalesPermissions::ORDER_VIEW, salesStore('sa')))->toBeFalse()
        ->and(Fx::allows(SalesPermissions::SETTINGS_UPDATE, salesStore('sa')))->toBeFalse()
        ->and(Fx::allows(SalesPermissions::GUEST_CART_MANAGE, salesStore('sa')))->toBeFalse();
});
