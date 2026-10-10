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
use Modules\Pricing\Application\PricingPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Pricing's permissions in Access's catalog (pricing.md §3): three jobs a role may hold - per store,
| in the Pricing group, any role (owner, 2026-10-08) - and the system's three, reserved. Their names in
| both languages are checked with every module's by Access's own test.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string> the names Pricing declared with that reservation, sorted
 */
function pricingDeclaredPermissions(bool $reserved): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'pricing.') && $permission->reserved === $reserved),
    ));
    sort($names);

    return $names;
}

// Two literal lists, split by the reservation, so a name moved from one list to the other is caught.
it('declares the three jobs of the spec as jobs, and nothing else', function () {
    expect(pricingDeclaredPermissions(reserved: false))->toBe([
        'pricing.category_discount.manage',
        'pricing.price.edit',
        'pricing.wholesale.edit',
    ]);
});

it('reserves exactly the three system jobs, and nothing else', function () {
    expect(pricingDeclaredPermissions(reserved: true))->toBe([
        'pricing.candidates.rebuild',
        'pricing.windows.apply',
        'pricing.windows.check',
    ]);
});

it('makes every job per store, offered in the role editor under Pricing, and never reserved', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->audience)->toBe(PermissionAudience::Role)
        ->and($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::Pricing)
        ->and($permission?->reserved)->toBeFalse()
        ->and($permission?->adminOnly)->toBeFalse()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->toContain($name);
})->with(PricingPermissions::jobs());

it('lets a staff role hold all three jobs, as an admin role may (any role, owner 2026-10-08)', function () {
    // Creating the role goes through Access's own rules, which refuse an admin-only action in a
    // staff role - so a staff role holding all three is the proof.
    $roleId = Fx::role(PricingPermissions::jobs(), RoleLevel::Staff);

    expect(Fx::rolePermissions($roleId))->toEqualCanonicalizing(PricingPermissions::jobs());
});

it('calls the three jobs by the names the owner accepted (2026-10-08)', function () {
    expect(trans('pricing::permissions.price.edit', [], 'en'))->toBe('Edit Prices and Sales')
        ->and(trans('pricing::permissions.wholesale.edit', [], 'en'))->toBe('Edit Wholesale Prices')
        ->and(trans('pricing::permissions.category_discount.manage', [], 'en'))->toBe('Manage Category Discounts');
});

it('reserves the system jobs, store-free and never offered', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->reserved)->toBeTrue()
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->not->toContain($name);
})->with(PricingPermissions::reserved());
