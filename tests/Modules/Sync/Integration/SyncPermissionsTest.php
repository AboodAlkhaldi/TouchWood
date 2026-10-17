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
use Modules\Sync\Application\SyncPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Sync's permissions in Access's catalog (sync.md §3): two jobs - View the Sync Report and Run Sync
| Reads, per store, any role, in the Sync group (owner, 2026-10-13, §9.2 #13; access.md amendment 68) -
| and three reserved: wiring a store (Super Admins only, as switching a store is), the system's reads
| and the pruning of the log.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string> the names Sync declared with that reservation, sorted
 */
function syncDeclaredPermissions(bool $reserved): array
{
    $names = array_values(array_map(
        static fn (PermissionDefinitionDto $permission): string => $permission->name,
        array_filter(app(InMemoryPermissionCatalog::class)->all(), static fn (PermissionDefinitionDto $permission): bool => str_starts_with($permission->name, 'sync.') && $permission->reserved === $reserved),
    ));
    sort($names);

    return $names;
}

// Two literal lists, split by the reservation, so a name moved from one list to the other is caught.
it('declares the two jobs of the spec as jobs, and nothing else', function () {
    expect(syncDeclaredPermissions(reserved: false))->toBe(['sync.read.run', 'sync.report.view']);
});

it('reserves exactly wiring, the system\'s reads and the pruning, and nothing else', function () {
    expect(syncDeclaredPermissions(reserved: true))->toBe([
        'sync.connection.manage',
        'sync.log.prune',
        'sync.read.scheduled',
    ]);
});

it('makes each job per store, offered in the role editor under Sync, never reserved, open to any role', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->audience)->toBe(PermissionAudience::Role)
        ->and($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::Sync)
        ->and($permission?->reserved)->toBeFalse()
        ->and($permission?->adminOnly)->toBeFalse()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->toContain($name);
})->with(SyncPermissions::jobs());

it('lets a staff role hold both jobs', function () {
    // Creating the role goes through Access's own rules, which refuse an admin-only action in a staff
    // role - so a staff role holding them is the proof.
    $roleId = Fx::role(SyncPermissions::jobs(), RoleLevel::Staff);

    expect(Fx::rolePermissions($roleId))->toEqualCanonicalizing(SyncPermissions::jobs());
});

it('reserves wiring and the system\'s jobs, store-free and never offered', function (string $name) {
    $permission = app(InMemoryPermissionCatalog::class)->definition($name);

    expect($permission?->reserved)->toBeTrue()
        ->and($permission?->kind)->toBe(PermissionKind::Global)
        ->and($permission?->group)->toBeNull()
        ->and(array_map(static fn (PermissionDefinitionDto $offered): string => $offered->name, app(InMemoryPermissionCatalog::class)->assignable()))->not->toContain($name);
})->with(SyncPermissions::reserved());

it('names the group and the two jobs as the owner did, in English and in Arabic', function () {
    expect(trans(PermissionGroup::Sync->labelKey(), [], 'en'))->toBe('Sync')
        ->and(trans('sync::permissions.report.view', [], 'en'))->toBe('View the Sync Report')
        ->and(trans('sync::permissions.read.run', [], 'en'))->toBe('Run Sync Reads');

    // Arabic is read with no fallback, so a line missing in Arabic cannot pass on the English one.
    app('translator')->setFallback('ar');

    expect(trans(PermissionGroup::Sync->labelKey(), [], 'ar'))->toBe('المزامنة')
        ->and(trans('sync::permissions.report.view', [], 'ar'))->toBe('عرض تقرير المزامنة')
        ->and(trans('sync::permissions.read.run', [], 'ar'))->toBe('تشغيل قراءات المزامنة');
});
