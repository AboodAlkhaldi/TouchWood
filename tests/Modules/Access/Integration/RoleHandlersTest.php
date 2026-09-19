<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Command\CloneRole\CloneRole;
use Modules\Access\Application\Command\CloneRole\CloneRoleHandler;
use Modules\Access\Application\Command\CreateRole\CreateRole;
use Modules\Access\Application\Command\CreateRole\CreateRoleHandler;
use Modules\Access\Application\Command\DeleteRole\DeleteRole;
use Modules\Access\Application\Command\DeleteRole\DeleteRoleHandler;
use Modules\Access\Application\Command\UpdateRole\UpdateRole;
use Modules\Access\Application\Command\UpdateRole\UpdateRoleHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\AdminOnlyPermission;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\PermissionEscalation;
use Modules\Access\Domain\Exception\ReservedPermission;
use Modules\Access\Domain\Exception\RoleInUse;
use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\Exception\UnknownPermission;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * An admin of these stores who manages roles and holds these business actions.
 *
 * @param  list<string>  $stores
 * @param  list<string>  $actions
 */
function roleAdmin(array $stores, array $actions = [PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]): string
{
    $adminId = Fx::staffWith([AccessPermissions::ROLE_MANAGE, AccessPermissions::STAFF_ASSIGN_ROLE, ...$actions], $stores, RoleLevel::Admin);
    Fx::actAsStaff($adminId);

    return $adminId;
}

/**
 * @param  list<string>  $permissions
 */
function createRole(RoleLevel $level, array $permissions, string $nameEn = 'Support', string $nameAr = 'الدعم'): string
{
    return app(CreateRoleHandler::class)->handle(new CreateRole($nameAr, $nameEn, $level, $permissions));
}

/**
 * @return list<string>
 */
function rolePermissions(string $roleId): array
{
    /** @var list<string> */
    return DB::table('access.role_permissions')->where('role_id', $roleId)->orderBy('permission')->pluck('permission')->all();
}

describe('creating a role', function () {
    it('saves a staff role with the author\'s own actions, and audits it', function () {
        roleAdmin(['sa']);

        $roleId = createRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);

        expect(DB::table('access.roles')->where('id', $roleId)->first(['kind', 'level']))->toEqual((object) ['kind' => 'SAVED', 'level' => 'STAFF'])
            ->and(rolePermissions($roleId))->toBe([PlatformPermissions::STORE_UPDATE])
            ->and(DB::table('platform.audit_entries')->where('action', 'access.role.created')->where('subject_id', $roleId)->exists())->toBeTrue();
    });

    it('refuses what a role cannot hold', function (array $permissions, string $error) {
        roleAdmin(['*']);

        expect(fn () => createRole(RoleLevel::Staff, Fx::names($permissions)))->toThrow($error);
    })->with([
        'an undeclared action' => [['catalog.product.update'], UnknownPermission::class],
        'an automatic action' => [[AccessPermissions::ACCOUNT_REGISTER], UnknownPermission::class],
        'a Super Admin action' => [[PlatformPermissions::STORE_CREATE], ReservedPermission::class],
        'a management action in a staff role' => [[AccessPermissions::STAFF_INVITE], AdminOnlyPermission::class],
        'no action at all' => [[], InvalidAccessAttribute::class],
        'an action the author does not hold' => [[PlatformPermissions::SETTINGS_UPDATE], PermissionEscalation::class],
    ]);

    it('lets only a Super Admin create an admin role', function () {
        roleAdmin(['*']);
        expect(fn () => createRole(RoleLevel::Admin, [AccessPermissions::STAFF_VIEW]))->toThrow(SuperAdminOnly::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $roleId = createRole(RoleLevel::Admin, [AccessPermissions::STAFF_INVITE, PlatformPermissions::STORE_UPDATE]);

        expect(rolePermissions($roleId))->toBe([AccessPermissions::STAFF_INVITE, PlatformPermissions::STORE_UPDATE]);
    });

    it('refuses staff who do not manage roles', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']));

        expect(fn () => createRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]))->toThrow(Unauthorized::class);
    });

    it('keeps saved role names unique in each language, ignoring case', function (string $nameAr, string $nameEn) {
        roleAdmin(['*']);
        createRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE], 'Support', 'الدعم');

        expect(fn () => createRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE], $nameEn, $nameAr))->toThrow(RoleNameTaken::class);
    })->with([
        'the English name in other letters' => ['دعم آخر', 'SUPPORT'],
        'the Arabic name' => ['الدعم', 'Help desk'],
    ]);
});

describe('cloning a role', function () {
    it('copies a saved role at this moment, never linked to it', function () {
        roleAdmin(['*']);
        $sourceId = createRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);

        $cloneId = app(CloneRoleHandler::class)->handle(new CloneRole($sourceId, 'نسخة', 'Copy'));
        app(UpdateRoleHandler::class)->handle(new UpdateRole($sourceId, permissions: [PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]));

        expect(rolePermissions($cloneId))->toBe([PlatformPermissions::STORE_UPDATE])
            ->and(DB::table('platform.audit_entries')->where('subject_id', $cloneId)->value('changes'))->toContain($sourceId);
    });

    it('refuses to clone a role holding an action the author does not hold', function () {
        $sourceId = Fx::role([PlatformPermissions::SETTINGS_UPDATE]);
        roleAdmin(['*']);

        expect(fn () => app(CloneRoleHandler::class)->handle(new CloneRole($sourceId, 'نسخة', 'Copy')))->toThrow(PermissionEscalation::class);
    });

    it('lets only a Super Admin clone an admin role', function () {
        $sourceId = Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin);
        roleAdmin(['*']);

        expect(fn () => app(CloneRoleHandler::class)->handle(new CloneRole($sourceId, 'نسخة', 'Copy')))->toThrow(SuperAdminOnly::class);
    });
});

describe('editing a saved role', function () {
    it('changes it for everyone who holds it', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $holderId = Fx::staff();
        Fx::assign($holderId, $roleId, ['sa']);
        roleAdmin(['sa']);

        app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'Store keepers', permissions: [PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]));

        expect(rolePermissions($roleId))->toBe([PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::STORE_UPDATE])
            ->and(DB::table('access.roles')->where('id', $roleId)->value('name'))->toContain('Store keepers')
            ->and(DB::table('platform.audit_entries')->where('action', 'access.role.updated')->where('subject_id', $roleId)->exists())->toBeTrue();
    });

    it('is refused when a holder is in a store the author does not cover', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::assign(Fx::staff(), $roleId, ['sa', 'ae']);
        roleAdmin(['sa']);

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'Other')))->toThrow(Unauthorized::class);
    });

    it('is refused when an action would reach a holder\'s store where the author does not hold it', function () {
        $roleId = Fx::role([PlatformPermissions::MEDIA_UPLOAD]);
        Fx::assign(Fx::staff(), $roleId, ['sa', 'eg']);
        // Covers both stores, but holds store.update only in KSA.
        Fx::actAsStaff(Fx::staffWith(
            [AccessPermissions::ROLE_MANAGE, PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::STORE_UPDATE],
            ['sa', 'eg'],
            RoleLevel::Admin,
            [PlatformPermissions::STORE_UPDATE => ['sa']],
        ));

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, permissions: [PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::STORE_UPDATE])))
            ->toThrow(PermissionEscalation::class)
            ->and(rolePermissions($roleId))->toBe([PlatformPermissions::MEDIA_UPLOAD]);
    });

    it('drops the holders\' exceptions for an action taken out of the role', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::SETTINGS_UPDATE]);
        $holderId = Fx::staff();
        Fx::assign($holderId, $roleId, ['sa', 'eg'], [PlatformPermissions::SETTINGS_UPDATE => ['sa']]);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, permissions: [PlatformPermissions::STORE_UPDATE]));

        expect(DB::table('access.role_assignment_exceptions')->where('staff_user_id', $holderId)->count())->toBe(0);
    });

    it('lets only a Super Admin edit an admin role', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin);
        roleAdmin(['*']);

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'Other')))->toThrow(SuperAdminOnly::class);
    });

    it('refuses to empty a role', function () {
        roleAdmin(['*']);
        $roleId = createRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, permissions: [])))->toThrow(InvalidAccessAttribute::class);
    });

    it('refuses a name another saved role has, but not its own', function () {
        roleAdmin(['*']);
        createRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE], 'Support', 'الدعم');
        $roleId = createRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE], 'Sales', 'المبيعات');

        app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'SALES'));

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'support')))->toThrow(RoleNameTaken::class);
    });
});

describe('deleting a saved role', function () {
    it('deletes a role nobody holds', function () {
        roleAdmin(['*']);
        $roleId = createRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);

        app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId));

        expect(DB::table('access.roles')->where('id', $roleId)->exists())->toBeFalse()
            ->and(rolePermissions($roleId))->toBe([])
            ->and(DB::table('platform.audit_entries')->where('action', 'access.role.deleted')->where('subject_id', $roleId)->exists())->toBeTrue();
    });

    it('needs a replacement when someone holds it, and moves every holder to it', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $replacementId = Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]);
        $holderId = Fx::staff();
        Fx::assign($holderId, $roleId, ['sa']);
        roleAdmin(['sa']);

        expect(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId)))->toThrow(RoleInUse::class);

        app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId, $replacementId));

        expect(DB::table('access.role_assignments')->where('staff_user_id', $holderId)->value('role_id'))->toBe($replacementId)
            ->and(DB::table('access.roles')->where('id', $roleId)->exists())->toBeFalse();
    });

    it('refuses a replacement of another level, a personal role, or the role itself', function (Closure $replacement) {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::assign(Fx::staff(), $roleId, ['sa']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId, $replacement($roleId))))->toThrow(InvalidAccessAttribute::class);
    })->with([
        'an admin role for staff' => [fn (string $roleId) => Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin)],
        'the role itself' => [fn (string $roleId) => $roleId],
    ]);

    it('refuses an unknown replacement', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::assign(Fx::staff(), $roleId, ['sa']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId, '01j8z3k4m5n6p7q8r9s0t1v2w3')))->toThrow(RoleNotFound::class);
    });

    it('is refused when the replacement gives a holder more than the author holds there', function () {
        $roleId = Fx::role([PlatformPermissions::MEDIA_UPLOAD]);
        $replacementId = Fx::role([PlatformPermissions::SETTINGS_UPDATE]);
        Fx::assign(Fx::staff(), $roleId, ['sa']);
        roleAdmin(['sa']);

        expect(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId, $replacementId)))->toThrow(PermissionEscalation::class)
            ->and(DB::table('access.roles')->where('id', $roleId)->exists())->toBeTrue();
    });
});
