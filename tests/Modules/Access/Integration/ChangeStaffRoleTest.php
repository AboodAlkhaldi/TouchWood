<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Command\ChangeStaffRole\ActionStores;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRole;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRoleHandler;
use Modules\Access\Application\Command\ChangeStaffRole\PersonalRole;
use Modules\Access\Application\Command\RefreshRolePermissions\RefreshRolePermissions;
use Modules\Access\Application\Command\RefreshRolePermissions\RefreshRolePermissionsHandler;
use Modules\Access\Application\Command\RefreshStaffPermissions\RefreshStaffPermissions;
use Modules\Access\Application\Command\RefreshStaffPermissions\RefreshStaffPermissionsHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\AdminOnlyPermission;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\PermissionEscalation;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\StaffNotEditable;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * An admin of these stores who assigns roles and holds store.update and media.upload there.
 *
 * @param  list<string>  $stores
 */
function assigningAdmin(array $stores): string
{
    $adminId = Fx::staffWith([AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD], $stores, RoleLevel::Admin);
    Fx::actAsStaff($adminId);

    return $adminId;
}

/**
 * @param  list<string>  $storeCodes  ['*'] for all stores
 * @param  list<ActionStores>  $exceptions
 */
function changeRole(string $staffId, array $storeCodes, ?string $savedRoleId = null, ?PersonalRole $personal = null, array $exceptions = []): void
{
    app(ChangeStaffRoleHandler::class)->handle(new ChangeStaffRole(
        $staffId,
        $storeCodes === ['*'] ? AccessLevel::AllStores : AccessLevel::SelectedStores,
        $storeCodes === ['*'] ? [] : array_map(Fx::storeId(...), $storeCodes),
        $exceptions,
        $savedRoleId,
        $personal,
    ));
}

function ownStores(string $permission, string ...$codes): ActionStores
{
    return new ActionStores($permission, AccessLevel::SelectedStores, array_values(array_map(Fx::storeId(...), $codes)));
}

/**
 * @return array{role_id: string, kind: string, stores: list<string>}
 */
function assignmentOf(string $staffId): array
{
    $roleId = (string) DB::table('access.role_assignments')->where('staff_user_id', $staffId)->value('role_id');
    /** @var list<string> $codes */
    $codes = DB::table('access.role_assignment_stores as s')->join('platform.stores as p', 'p.id', '=', 's.store_id')
        ->where('s.staff_user_id', $staffId)->orderBy('p.code')->pluck('p.code')->all();

    return ['role_id' => $roleId, 'kind' => (string) DB::table('access.roles')->where('id', $roleId)->value('kind'), 'stores' => $codes];
}

describe('keeping a saved role as it is', function () {
    it('gives the staff member that saved role in the chosen stores, and audits it', function () {
        assigningAdmin(['sa', 'ae']);
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $staffId = Fx::staff();

        changeRole($staffId, ['sa'], $roleId);

        expect(assignmentOf($staffId))->toBe(['role_id' => $roleId, 'kind' => 'SAVED', 'stores' => ['sa']])
            ->and(DB::table('platform.audit_entries')->where('action', 'access.staff_user.role_changed')->where('subject_id', $staffId)->exists())->toBeTrue();
    });

    it('refuses a role that is not a saved one', function () {
        assigningAdmin(['*']);

        expect(fn () => changeRole(Fx::staff(), ['sa'], '01j8z3k4m5n6p7q8r9s0t1v2w3'))->toThrow(RoleNotFound::class);
    });
});

describe('editing it into a personal role', function () {
    it('makes a role that belongs to that staff member only, and ends it when they move to a saved role', function () {
        assigningAdmin(['sa']);
        $savedId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $staffId = Fx::staff();
        $colleagueId = Fx::staff();
        Fx::assign($colleagueId, $savedId, ['sa']);

        changeRole($staffId, ['sa'], personal: new PersonalRole('الدعم', 'Support', [PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]));
        $personalId = assignmentOf($staffId)['role_id'];

        expect(assignmentOf($staffId)['kind'])->toBe('PERSONAL')
            ->and(DB::table('access.roles')->where('id', $personalId)->value('personal_to'))->toBe($staffId)
            // Nobody else changed.
            ->and(assignmentOf($colleagueId)['role_id'])->toBe($savedId);

        // Editing it again changes the same role, in place.
        changeRole($staffId, ['sa'], personal: new PersonalRole('الدعم', 'Support', [PlatformPermissions::MEDIA_UPLOAD]));
        expect(assignmentOf($staffId)['role_id'])->toBe($personalId);

        changeRole($staffId, ['sa'], $savedId);

        expect(assignmentOf($staffId)['role_id'])->toBe($savedId)
            ->and(DB::table('access.roles')->where('id', $personalId)->exists())->toBeFalse();
    });

    it('holds only actions the author holds, and no management action in a staff role', function (array $permissions, string $error) {
        assigningAdmin(['*']);

        expect(fn () => changeRole(Fx::staff(), ['sa'], personal: new PersonalRole('دور', 'Role', Fx::names($permissions))))->toThrow($error);
    })->with([
        'an action the author lacks' => [[PlatformPermissions::SETTINGS_UPDATE], PermissionEscalation::class],
        'a management action' => [[AccessPermissions::STAFF_INVITE], AdminOnlyPermission::class],
        'nothing' => [[], InvalidAccessAttribute::class],
    ]);
});

describe('stores', function () {
    it('keeps a store row and an action\'s own stores', function () {
        assigningAdmin(['sa', 'ae']);
        $staffId = Fx::staff();

        changeRole($staffId, ['sa', 'ae'], Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]), exceptions: [ownStores(PlatformPermissions::STORE_UPDATE, 'sa')]);
        $grants = app(GrantsReader::class)->forStaff($staffId);

        expect($grants?->storesFor(PlatformPermissions::STORE_UPDATE)?->storeIds())->toBe([Fx::storeId('sa')])
            ->and($grants?->stores?->storeIds())->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('ae')]);
    });

    it('refuses stores the author does not hold an action in', function () {
        assigningAdmin(['sa']);

        expect(fn () => changeRole(Fx::staff(), ['sa', 'eg'], Fx::role([PlatformPermissions::STORE_UPDATE])))->toThrow(Unauthorized::class);
    });

    it('refuses an action reaching a store where the author lacks it, even inside their stores', function () {
        Fx::actAsStaff(Fx::staffWith(
            [AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE],
            ['sa', 'ae'],
            RoleLevel::Admin,
            [PlatformPermissions::STORE_UPDATE => ['sa']],
        ));

        expect(fn () => changeRole(Fx::staff(), ['sa', 'ae'], Fx::role([PlatformPermissions::STORE_UPDATE])))->toThrow(PermissionEscalation::class);
    });

    it('needs All stores to give all stores', function () {
        assigningAdmin(['sa', 'eg', 'ae']);

        expect(fn () => changeRole(Fx::staff(), ['*'], Fx::role([PlatformPermissions::STORE_UPDATE])))->toThrow(Unauthorized::class);
    });

    it('refuses exceptions for an action outside the role, for a store-free action, or twice', function (Closure $exceptions) {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => changeRole(Fx::staff(), ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]), exceptions: $exceptions()))
            ->toThrow(InvalidAccessAttribute::class);
    })->with([
        'not in the role' => [fn () => [ownStores(PlatformPermissions::SETTINGS_UPDATE, 'sa')]],
        'store-free' => [fn () => [ownStores(PlatformPermissions::MEDIA_UPLOAD, 'sa')]],
        'twice' => [fn () => [ownStores(PlatformPermissions::STORE_UPDATE, 'sa'), ownStores(PlatformPermissions::STORE_UPDATE, 'sa')]],
    ]);

    it('refuses an unknown store', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(ChangeStaffRoleHandler::class)->handle(new ChangeStaffRole(Fx::staff(), AccessLevel::SelectedStores, ['01j8z3k4m5n6p7q8r9s0t1v2w3'], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]))))
            ->toThrow(InvalidAccessAttribute::class, 'no store');
    });
});

describe('who may change whom', function () {
    it('lets an admin change only staff whose stores all lie within theirs', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $ksaOnly = Fx::staff();
        $ksaAndUae = Fx::staff();
        Fx::assign($ksaOnly, $roleId, ['sa']);
        Fx::assign($ksaAndUae, $roleId, ['sa', 'ae']);
        assigningAdmin(['sa']);

        changeRole($ksaOnly, ['sa'], $roleId);

        // Not even the KSA part of a KSA+UAE staff member (owner, 2026-09-19).
        expect(fn () => changeRole($ksaAndUae, ['sa'], $roleId))->toThrow(Unauthorized::class);
    });

    it('never lets an admin change an admin, a Super Admin or themselves', function (Closure $target, string $reason) {
        $adminId = assigningAdmin(['*']);

        expect(fn () => changeRole($target($adminId), ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE])))
            ->toThrow(StaffNotEditable::class, "({$reason})");
    })->with([
        'another admin' => [fn () => Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], RoleLevel::Admin), 'admin'],
        'a Super Admin' => [fn () => Fx::staff(superAdmin: true), 'super_admin'],
        'themselves' => [fn (string $adminId) => $adminId, 'yourself'],
    ]);

    it('lets only a Super Admin give an admin role', function () {
        $adminRole = Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin);
        assigningAdmin(['*']);

        expect(fn () => changeRole(Fx::staff(), ['sa'], $adminRole))->toThrow(SuperAdminOnly::class)
            ->and(fn () => changeRole(Fx::staff(), ['sa'], personal: new PersonalRole('دور', 'Role', [PlatformPermissions::STORE_UPDATE], RoleLevel::Admin)))->toThrow(SuperAdminOnly::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $staffId = Fx::staff();
        changeRole($staffId, ['sa'], $adminRole);

        expect(assignmentOf($staffId)['role_id'])->toBe($adminRole);
    });

    it('refuses staff who do not assign roles, and an unknown staff member', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']));
        expect(fn () => changeRole(Fx::staff(), ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE])))->toThrow(Unauthorized::class);

        assigningAdmin(['*']);
        expect(fn () => changeRole('01j8z3k4m5n6p7q8r9s0t1v2w3', ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE])))->toThrow(StaffNotFound::class);
    });

    it('needs exactly one of a saved role and a personal role', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => changeRole(Fx::staff(), ['sa']))->toThrow(InvalidAccessAttribute::class)
            ->and(fn () => changeRole(Fx::staff(), ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE]), new PersonalRole('دور', 'Role', [PlatformPermissions::STORE_UPDATE])))->toThrow(InvalidAccessAttribute::class);
    });
});

describe('refreshing cached permissions by hand', function () {
    it('rebuilds a staff member\'s cached permissions for whoever may change their role', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $before = app(GrantsReader::class)->forStaff($staffId);
        // Written behind the cache's back: only a refresh shows it.
        DB::table('access.role_assignment_stores')->insert(['staff_user_id' => $staffId, 'store_id' => Fx::storeId('eg')]);
        assigningAdmin(['*']);

        app(RefreshStaffPermissionsHandler::class)->handle(new RefreshStaffPermissions($staffId));

        expect($before?->stores?->storeIds())->toBe([Fx::storeId('sa')])
            ->and(app(GrantsReader::class)->forStaff($staffId)?->stores?->storeIds())->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('eg')]);
    });

    it('refuses to refresh staff outside the author\'s stores', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['ae']);
        assigningAdmin(['sa']);

        expect(fn () => app(RefreshStaffPermissionsHandler::class)->handle(new RefreshStaffPermissions($staffId)))->toThrow(Unauthorized::class);
    });

    it('rebuilds a role\'s holders for whoever may edit the role', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $holderId = Fx::staff();
        Fx::assign($holderId, $roleId, ['sa']);
        app(GrantsReader::class)->forStaff($holderId);
        DB::table('access.role_permissions')->insert(['role_id' => $roleId, 'permission' => PlatformPermissions::MEDIA_UPLOAD]);
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::ROLE_MANAGE, PlatformPermissions::STORE_UPDATE], ['sa'], RoleLevel::Admin));

        app(RefreshRolePermissionsHandler::class)->handle(new RefreshRolePermissions($roleId));

        expect(app(GrantsReader::class)->forStaff($holderId)?->storesFor(PlatformPermissions::MEDIA_UPLOAD))->not->toBeNull();
    });
});
