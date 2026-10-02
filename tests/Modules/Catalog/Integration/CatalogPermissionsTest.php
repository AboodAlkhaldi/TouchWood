<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Catalog\Application\CatalogPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Catalog's permissions in Access's catalog (catalog.md §3): one per job, seventeen a role may hold —
| per store, in the Catalog group, none admin-only — and three reserved to a Super Admin and the
| system. Their names in both languages are checked with every module's by Access's own test.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string> the names Catalog declared, sorted
 */
function catalogDeclaredPermissions(): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'catalog.')),
    ));
    sort($names);

    return $names;
}

it('declares the seventeen jobs and the three reserved permissions of the spec, and nothing else', function () {
    expect(catalogDeclaredPermissions())->toBe([
        'catalog.attribute.manage',
        'catalog.brand.manage',
        'catalog.category.manage',
        'catalog.category.rank',
        'catalog.import.run',
        'catalog.label.manage',
        'catalog.listing.choose',
        'catalog.listing.labels',
        'catalog.listing.rebuild',
        'catalog.listing.selling',
        'catalog.listing.unavailable',
        'catalog.product.archive',
        'catalog.product.create',
        'catalog.product.publish',
        'catalog.product.update',
        'catalog.product.view',
        'catalog.search_log.prune',
        'catalog.search_word.manage',
        'catalog.variant.correct_code',
        'catalog.warranty.manage',
    ]);
});

it('makes every job per store, offered in the role editor under Catalog, and never reserved', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->audience)->toBe(PermissionAudience::Role)
        ->and($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::Catalog)
        ->and($permission?->reserved)->toBeFalse()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->toContain($name);
})->with(CatalogPermissions::jobs());

it('keeps every job open to staff roles as well as admin roles: none is admin-only', function () {
    // Creating the role goes through Access's own rules, which refuse an admin-only action in a
    // staff role — so a staff role holding all seventeen is the proof.
    $roleId = Fx::role(CatalogPermissions::jobs(), RoleLevel::Staff);

    expect(array_intersect(CatalogPermissions::jobs(), AccessPermissions::adminOnly()))->toBe([])
        ->and(Fx::rolePermissions($roleId))->toEqualCanonicalizing(CatalogPermissions::jobs());
});

it('lists the six shared-list jobs among the jobs, each checked with All stores by its handlers', function () {
    expect(CatalogPermissions::sharedLists())->toHaveCount(6)
        ->and(array_diff(CatalogPermissions::sharedLists(), CatalogPermissions::jobs()))->toBe([]);
});

it('reserves the import and the system jobs to a Super Admin, store-free and never offered', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->reserved)->toBeTrue()
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->not->toContain($name);
})->with(CatalogPermissions::reserved());
