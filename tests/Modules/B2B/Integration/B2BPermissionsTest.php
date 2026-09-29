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
use Modules\B2B\Application\B2BPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| B2B's permissions in Access's catalog (b2b.md §3, amendment 10): the company's own two, automatic
| and store-free, and the twelve staff jobs — per store, in the Companies group, none admin-only.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('declares the twelve staff jobs the owner confirmed, and nothing else for staff (amendments 10 and 11(c))', function () {
    $staff = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'b2b.') && $permission->audience === PermissionAudience::Role),
    ));
    sort($staff);

    expect($staff)->toBe([
        'b2b.company.correct_type',
        'b2b.company.review',
        'b2b.company.suspend',
        'b2b.company.transfer_type',
        'b2b.company.view',
        'b2b.company_document.view',
        'b2b.company_type.create',
        'b2b.company_type.deactivate',
        'b2b.company_type.update',
        'b2b.document_type.create',
        'b2b.document_type.deactivate',
        'b2b.document_type.update',
    ]);
});

it('makes every staff job per store, offered in the role editor under Companies, and never reserved', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::Companies)
        ->and($permission?->reserved)->toBeFalse()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->toContain($name);
})->with(B2BPermissions::staff());

it('keeps every staff job open to staff roles as well as admin roles: none is admin-only (amendment 10)', function () {
    // Creating the role goes through Access's own rules, which refuse an admin-only action in a
    // staff role — so a staff role holding all twelve is the proof.
    $roleId = Fx::role(B2BPermissions::staff(), RoleLevel::Staff);

    expect(array_intersect(B2BPermissions::staff(), AccessPermissions::adminOnly()))->toBe([])
        ->and(Fx::rolePermissions($roleId))->toEqualCanonicalizing(B2BPermissions::staff());
});

it('keeps the company\'s own two automatic and store-free', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->audience)->toBe(PermissionAudience::EveryCustomer)
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull();
})->with([B2BPermissions::APPLY, B2BPermissions::UPDATE]);
