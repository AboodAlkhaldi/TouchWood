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
 * @return list<string> the names Catalog declared with that reservation, sorted
 */
function catalogDeclaredPermissions(bool $reserved): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'catalog.') && $permission->reserved === $reserved),
    ));
    sort($names);

    return $names;
}

// Two literal lists, split by the reservation, so a name moved from one list to the other is caught
// (review of step 1): the union alone would let the import become a job any role may hold.
it('declares the seventeen jobs of the spec as jobs, and nothing else', function () {
    expect(catalogDeclaredPermissions(reserved: false))->toBe([
        'catalog.attribute.manage',
        'catalog.brand.manage',
        'catalog.category.manage',
        'catalog.category.rank',
        'catalog.label.manage',
        'catalog.listing.choose',
        'catalog.listing.labels',
        'catalog.listing.selling',
        'catalog.listing.unavailable',
        'catalog.product.archive',
        'catalog.product.create',
        'catalog.product.publish',
        'catalog.product.update',
        'catalog.product.view',
        'catalog.search_word.manage',
        'catalog.variant.correct_code',
        'catalog.warranty.manage',
    ]);
});

it('reserves exactly the import and the two system jobs, and nothing else', function () {
    expect(catalogDeclaredPermissions(reserved: true))->toBe([
        'catalog.import.run',
        'catalog.listing.rebuild',
        'catalog.search_log.prune',
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

it('names exactly the six shared-list jobs of the spec, which their handlers will check with All stores', function () {
    $shared = CatalogPermissions::sharedLists();
    sort($shared);

    expect($shared)->toBe([
        'catalog.attribute.manage',
        'catalog.brand.manage',
        'catalog.category.manage',
        'catalog.label.manage',
        'catalog.search_word.manage',
        'catalog.warranty.manage',
    ])->and(array_diff($shared, CatalogPermissions::jobs()))->toBe([]);
});

it('reserves the import and the system jobs to a Super Admin, store-free and never offered', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->reserved)->toBeTrue()
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->not->toContain($name);
})->with(CatalogPermissions::reserved());
