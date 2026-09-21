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

const ASSIGNING_ADMIN_ACTIONS = [AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD];

/**
 * @param  list<string>  $stores  store codes, ['*'] for all stores
 * @param  array<string, list<string>>  $exceptions  permission => store codes
 */
function changeRole(string $staffId, array $stores, ?string $savedRoleId = null, ?PersonalRole $personal = null, array $exceptions = []): void
{
    app(ChangeStaffRoleHandler::class)->handle(Fx::change($staffId, $stores, $exceptions, $savedRoleId, $personal));
}

/**
 * @return list<string> the store codes of the staff member's store row
 */
function storeRowOf(string $staffId): array
{
    /** @var list<string> */
    return DB::table('access.role_assignment_stores as s')->join('platform.stores as p', 'p.id', '=', 's.store_id')
        ->where('s.staff_user_id', $staffId)->orderBy('p.code')->pluck('p.code')->all();
}

function roleKindOf(string $staffId): string
{
    return (string) DB::table('access.roles')->where('id', Fx::roleOf($staffId))->value('kind');
}

describe('keeping a saved role as it is', function () {
    it('gives the staff member that saved role in the chosen stores, and audits it', function () {
        Fx::actAsAdmin(['sa', 'ae'], ASSIGNING_ADMIN_ACTIONS);
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $staffId = Fx::staff();

        changeRole($staffId, ['sa'], $roleId);

        expect(Fx::roleOf($staffId))->toBe($roleId)
            ->and(roleKindOf($staffId))->toBe('SAVED')
            ->and(storeRowOf($staffId))->toBe(['sa'])
            ->and(Fx::audits('access.staff_user.role_changed', $staffId))->toBe(1);
    });

    it('writes and audits nothing when the same role and stores are given again', function () {
        Fx::actAsAdmin(['sa'], ASSIGNING_ADMIN_ACTIONS);
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $staffId = Fx::staff();
        changeRole($staffId, ['sa'], $roleId);

        changeRole($staffId, ['sa'], $roleId);

        expect(Fx::audits('access.staff_user.role_changed', $staffId))->toBe(1);
    });

    it('refuses an unknown role, and someone else\'s personal role', function () {
        Fx::actAsAdmin(['*'], ASSIGNING_ADMIN_ACTIONS);
        $personalId = Fx::personalRole(Fx::staff(), [PlatformPermissions::STORE_UPDATE]);

        expect(fn () => changeRole(Fx::staff(), ['sa'], '01j8z3k4m5n6p7q8r9s0t1v2w3'))->toThrow(RoleNotFound::class)
            ->and(fn () => changeRole(Fx::staff(), ['sa'], $personalId))->toThrow(RoleNotFound::class);
    });
});

describe('editing it into a personal role', function () {
    it('makes a role that belongs to that staff member only, edits it in place, and ends it when they move to a saved role', function () {
        Fx::actAsAdmin(['sa'], ASSIGNING_ADMIN_ACTIONS);
        $savedId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $staffId = Fx::staff();
        $colleagueId = Fx::staff();
        Fx::assign($colleagueId, $savedId, ['sa']);

        changeRole($staffId, ['sa'], personal: new PersonalRole('الدعم', 'Support', [PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]));
        $personalId = Fx::roleOf($staffId);

        expect(roleKindOf($staffId))->toBe('PERSONAL')
            ->and(DB::table('access.roles')->where('id', $personalId)->value('personal_to'))->toBe($staffId)
            ->and(Fx::audits('access.role.created', $personalId))->toBe(1)
            // Nobody else changed.
            ->and(Fx::roleOf($colleagueId))->toBe($savedId);

        // Editing it again changes the same role, in place.
        changeRole($staffId, ['sa'], personal: new PersonalRole('الدعم', 'Support', [PlatformPermissions::MEDIA_UPLOAD]));
        expect(Fx::roleOf($staffId))->toBe($personalId)
            ->and(Fx::rolePermissions($personalId))->toBe([PlatformPermissions::MEDIA_UPLOAD])
            ->and(Fx::audits('access.role.updated', $personalId))->toBe(1);

        changeRole($staffId, ['sa'], $savedId);

        expect(Fx::roleOf($staffId))->toBe($savedId)
            ->and(DB::table('access.roles')->where('id', $personalId)->exists())->toBeFalse()
            ->and(Fx::audits('access.role.deleted', $personalId))->toBe(1);
    });

    it('holds only actions the author holds, and no management action in a staff role', function (array $permissions, string $error) {
        Fx::actAsAdmin(['*'], ASSIGNING_ADMIN_ACTIONS);

        expect(fn () => changeRole(Fx::staff(), ['sa'], personal: new PersonalRole('دور', 'Role', Fx::names($permissions))))->toThrow($error);
    })->with([
        'an action the author lacks' => [[PlatformPermissions::SETTINGS_UPDATE], PermissionEscalation::class],
        'a management action' => [[AccessPermissions::STAFF_INVITE], AdminOnlyPermission::class],
        'nothing' => [[], InvalidAccessAttribute::class],
    ]);
});

describe('stores', function () {
    it('keeps a store row and an action\'s own stores, which may reach beyond the row', function () {
        Fx::actAsAdmin(['sa', 'ae'], ASSIGNING_ADMIN_ACTIONS);
        $staffId = Fx::staff();

        changeRole($staffId, ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]), exceptions: [PlatformPermissions::STORE_UPDATE => ['sa', 'ae']]);
        $grants = app(GrantsReader::class)->forStaff($staffId);

        expect($grants?->storesFor(PlatformPermissions::STORE_UPDATE)?->storeIds())->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('ae')])
            // Their stores: the row plus what the exception adds.
            ->and($grants?->stores?->storeIds())->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('ae')]);
    });

    it('refuses stores the author does not hold the action in', function () {
        Fx::actAsAdmin(['sa'], ASSIGNING_ADMIN_ACTIONS);

        expect(fn () => changeRole(Fx::staff(), ['sa', 'eg'], Fx::role([PlatformPermissions::STORE_UPDATE])))->toThrow(Unauthorized::class);
    });

    it('refuses an action reaching a store where the author lacks it, through the row or an exception', function (array $row, array $exceptions) {
        Fx::actAsAdmin(['sa', 'ae'], [AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE], [PlatformPermissions::STORE_UPDATE => ['sa']]);

        expect(fn () => changeRole(Fx::staff(), Fx::names($row), Fx::role([PlatformPermissions::STORE_UPDATE]), exceptions: $exceptions))->toThrow(PermissionEscalation::class);
    })->with([
        'the store row' => [['sa', 'ae'], []],
        'an exception' => [['sa'], [PlatformPermissions::STORE_UPDATE => ['sa', 'ae']]],
    ]);

    it('needs All stores to give all stores, through the row or an exception', function (array $row, array $exceptions) {
        Fx::actAsAdmin(['sa', 'eg', 'ae'], ASSIGNING_ADMIN_ACTIONS);

        expect(fn () => changeRole(Fx::staff(), Fx::names($row), Fx::role([PlatformPermissions::STORE_UPDATE]), exceptions: $exceptions))->toThrow(Unauthorized::class);
    })->with([
        'the store row' => [['*'], []],
        'an exception' => [['sa'], [PlatformPermissions::STORE_UPDATE => ['*']]],
    ]);

    it('refuses exceptions for an action outside the role, for a store-free action, or twice', function (Closure $command, string $message) {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(ChangeStaffRoleHandler::class)->handle($command(Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]))))
            ->toThrow(InvalidAccessAttribute::class, $message);
    })->with([
        'not in the role' => [fn (string $roleId) => Fx::change(Fx::staff(), ['sa'], [PlatformPermissions::SETTINGS_UPDATE => ['sa']], $roleId), 'not an action of the role'],
        'store-free' => [fn (string $roleId) => Fx::change(Fx::staff(), ['sa'], [PlatformPermissions::MEDIA_UPLOAD => ['sa']], $roleId), 'store-free'],
        'twice' => [function (string $roleId) {
            $command = Fx::change(Fx::staff(), ['sa'], [PlatformPermissions::STORE_UPDATE => ['sa']], $roleId);

            return new ChangeStaffRole($command->staffId, $command->accessLevel, $command->storeIds, [...$command->exceptions, ...$command->exceptions], $roleId);
        }, 'twice'],
    ]);

    it('refuses a store that does not exist, in the row or an exception', function (Closure $command) {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(ChangeStaffRoleHandler::class)->handle($command(Fx::role([PlatformPermissions::STORE_UPDATE]))))->toThrow(InvalidAccessAttribute::class, 'no store');
    })->with([
        'the row' => [fn (string $roleId) => new ChangeStaffRole(Fx::staff(), AccessLevel::SelectedStores, ['01j8z3k4m5n6p7q8r9s0t1v2w3'], savedRoleId: $roleId)],
        'an exception' => [function (string $roleId) {
            $command = Fx::change(Fx::staff(), ['sa'], [PlatformPermissions::STORE_UPDATE => ['sa']], $roleId);

            return new ChangeStaffRole($command->staffId, $command->accessLevel, $command->storeIds, [
                new ActionStores(PlatformPermissions::STORE_UPDATE, AccessLevel::SelectedStores, ['01j8z3k4m5n6p7q8r9s0t1v2w3']),
            ], $roleId);
        }],
    ]);
});

describe('who may change whom', function () {
    it('lets an admin change only staff whose stores all lie within theirs', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $ksaOnly = Fx::staff();
        $ksaAndUae = Fx::staff();
        $ksaWithUaeException = Fx::staff();
        Fx::assign($ksaOnly, $roleId, ['sa']);
        Fx::assign($ksaAndUae, $roleId, ['sa', 'ae']);
        Fx::assign($ksaWithUaeException, $roleId, ['sa'], [PlatformPermissions::STORE_UPDATE => ['ae']]);
        Fx::actAsAdmin(['sa'], ASSIGNING_ADMIN_ACTIONS);

        changeRole($ksaOnly, ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE]));

        // Not even the KSA part of a KSA+UAE staff member (owner, 2026-09-19), however the UAE part
        // was given.
        expect(fn () => changeRole($ksaAndUae, ['sa'], $roleId))->toThrow(Unauthorized::class)
            ->and(fn () => changeRole($ksaWithUaeException, ['sa'], $roleId))->toThrow(Unauthorized::class);
    });

    it('never lets an admin change an admin, a Super Admin or themselves', function (Closure $target, string $reason) {
        $adminId = Fx::actAsAdmin(['*'], ASSIGNING_ADMIN_ACTIONS);

        expect(fn () => changeRole($target($adminId), ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE])))
            ->toThrow(StaffNotEditable::class, "({$reason})");
    })->with([
        'another admin' => [fn () => Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], RoleLevel::Admin), 'admin'],
        'a Super Admin' => [fn () => Fx::staff(superAdmin: true), 'super_admin'],
        'themselves' => [fn (string $adminId) => $adminId, 'yourself'],
    ]);

    it('lets a Super Admin change an admin, but never another Super Admin', function () {
        $adminId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], RoleLevel::Admin);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        changeRole($adminId, ['sa', 'ae'], Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin));

        expect(storeRowOf($adminId))->toBe(['ae', 'sa'])
            ->and(fn () => changeRole(Fx::staff(superAdmin: true), ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE])))->toThrow(StaffNotEditable::class, '(super_admin)');
    });

    it('lets only a Super Admin give an admin role', function () {
        $adminRole = Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin);
        Fx::actAsAdmin(['*'], ASSIGNING_ADMIN_ACTIONS);

        expect(fn () => changeRole(Fx::staff(), ['sa'], $adminRole))->toThrow(SuperAdminOnly::class)
            ->and(fn () => changeRole(Fx::staff(), ['sa'], personal: new PersonalRole('دور', 'Role', [PlatformPermissions::STORE_UPDATE], RoleLevel::Admin)))->toThrow(SuperAdminOnly::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $staffId = Fx::staff();
        changeRole($staffId, ['sa'], $adminRole);

        expect(Fx::roleOf($staffId))->toBe($adminRole);
    });

    it('tells someone who may not assign roles nothing about which staff or roles exist', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']));

        expect(fn () => changeRole('01j8z3k4m5n6p7q8r9s0t1v2w3', ['sa'], '01j8z3k4m5n6p7q8r9s0t1v2w4'))->toThrow(Unauthorized::class)
            ->and(fn () => changeRole(Fx::staff(), ['sa'], Fx::role([PlatformPermissions::STORE_UPDATE])))->toThrow(Unauthorized::class);
    });

    it('refuses an unknown staff member to an admin who may assign roles', function () {
        Fx::actAsAdmin(['*'], ASSIGNING_ADMIN_ACTIONS);

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
        Fx::actAsAdmin(['*'], ASSIGNING_ADMIN_ACTIONS);

        expect(app(GrantsReader::class)->forStaff($staffId)?->stores?->storeIds())->toBe([Fx::storeId('sa')]);

        app(RefreshStaffPermissionsHandler::class)->handle(new RefreshStaffPermissions($staffId));

        expect($before?->stores?->storeIds())->toBe([Fx::storeId('sa')])
            ->and(app(GrantsReader::class)->forStaff($staffId)?->stores?->storeIds())->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('eg')]);
    });

    it('refuses to refresh staff outside the author\'s stores, an admin, a Super Admin, or yourself', function (Closure $target, string $error) {
        $adminId = Fx::actAsAdmin(['sa'], ASSIGNING_ADMIN_ACTIONS);

        expect(fn () => app(RefreshStaffPermissionsHandler::class)->handle(new RefreshStaffPermissions($target($adminId))))->toThrow($error);
    })->with([
        'outside their stores' => [fn () => Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['ae']), Unauthorized::class],
        'an admin' => [fn () => Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], RoleLevel::Admin), StaffNotEditable::class],
        'a Super Admin' => [fn () => Fx::staff(superAdmin: true), StaffNotEditable::class],
        'themselves' => [fn (string $adminId) => $adminId, StaffNotEditable::class],
    ]);

    it('refuses the refresh to staff who assign no roles', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']));

        expect(fn () => app(RefreshStaffPermissionsHandler::class)->handle(new RefreshStaffPermissions($staffId)))->toThrow(Unauthorized::class);
    });

    it('rebuilds a saved role\'s holders for whoever may edit the role', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $holderId = Fx::staff();
        Fx::assign($holderId, $roleId, ['sa']);
        Fx::warmCache($holderId);
        DB::table('access.role_permissions')->insert(['role_id' => $roleId, 'permission' => PlatformPermissions::MEDIA_UPLOAD]);
        Fx::actAsAdmin(['sa'], [AccessPermissions::ROLE_MANAGE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);

        expect(app(GrantsReader::class)->forStaff($holderId)?->storesFor(PlatformPermissions::MEDIA_UPLOAD))->toBeNull();

        app(RefreshRolePermissionsHandler::class)->handle(new RefreshRolePermissions($roleId));

        expect(app(GrantsReader::class)->forStaff($holderId)?->storesFor(PlatformPermissions::MEDIA_UPLOAD))->not->toBeNull();
    });

    it('refuses to refresh an admin role for an admin, a role held outside their stores, or a personal role', function (Closure $role, string $error) {
        Fx::actAsAdmin(['sa'], [AccessPermissions::ROLE_MANAGE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);

        expect(fn () => app(RefreshRolePermissionsHandler::class)->handle(new RefreshRolePermissions($role())))->toThrow($error);
    })->with([
        'an admin role' => [fn () => Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin), SuperAdminOnly::class],
        'held outside their stores' => [function () {
            $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
            Fx::assign(Fx::staff(), $roleId, ['ae']);

            return $roleId;
        }, Unauthorized::class],
        'a personal role' => [fn () => Fx::personalRole(Fx::staff(), [PlatformPermissions::STORE_UPDATE]), RoleNotFound::class],
    ]);
});
