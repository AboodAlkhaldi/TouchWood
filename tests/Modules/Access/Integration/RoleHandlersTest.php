<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRoleHandler;
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
use Modules\Platform\Infrastructure\Queue\JobActorState;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

const ROLE_ADMIN_ACTIONS = [AccessPermissions::ROLE_MANAGE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD];

/**
 * @param  list<string>  $permissions
 */
function newRole(RoleLevel $level, array $permissions, string $nameEn = 'Support', string $nameAr = 'الدعم'): string
{
    return app(CreateRoleHandler::class)->handle(new CreateRole($nameAr, $nameEn, $level, $permissions));
}

/**
 * Drops the unique indexes on saved role names for the rest of the test (PostgreSQL rolls DDL back
 * with the test's transaction), so only the code can refuse a taken name.
 */
function withoutNameIndexes(): void
{
    DB::statement('DROP INDEX access.roles_saved_name_ar');
    DB::statement('DROP INDEX access.roles_saved_name_en');
}

describe('creating a role', function () {
    it('saves a staff role with the author\'s own actions, and audits it', function () {
        Fx::actAsAdmin(['sa'], ROLE_ADMIN_ACTIONS);

        $roleId = newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);

        expect(DB::table('access.roles')->where('id', $roleId)->first(['kind', 'level']))->toEqual((object) ['kind' => 'SAVED', 'level' => 'STAFF'])
            ->and(Fx::rolePermissions($roleId))->toBe([PlatformPermissions::STORE_UPDATE])
            ->and(Fx::audits('access.role.created', $roleId))->toBe(1);
    });

    it('refuses what a role cannot hold', function (array $permissions, string $error) {
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);

        expect(fn () => newRole(RoleLevel::Staff, Fx::names($permissions)))->toThrow($error);
    })->with([
        'an undeclared action' => [['catalog.product.update'], UnknownPermission::class],
        'an automatic action' => [[AccessPermissions::ACCOUNT_REGISTER], UnknownPermission::class],
        'a Super Admin action' => [[PlatformPermissions::STORE_CREATE], ReservedPermission::class],
        'a management action in a staff role' => [[AccessPermissions::STAFF_INVITE], AdminOnlyPermission::class],
        // The staff security settings decide how everyone signs in; a store's own settings do not,
        // and stay an ordinary action (owner, 2026-09-21).
        'the staff security settings in a staff role' => [[AccessPermissions::STAFF_SETTINGS_UPDATE], AdminOnlyPermission::class],
        'no action at all' => [[], InvalidAccessAttribute::class],
        'an action the author does not hold' => [[PlatformPermissions::SETTINGS_UPDATE], PermissionEscalation::class],
    ]);

    it('lets only a Super Admin create an admin role', function () {
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);
        expect(fn () => newRole(RoleLevel::Admin, [AccessPermissions::STAFF_VIEW]))->toThrow(SuperAdminOnly::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $roleId = newRole(RoleLevel::Admin, [AccessPermissions::STAFF_INVITE, PlatformPermissions::STORE_UPDATE]);

        expect(Fx::rolePermissions($roleId))->toBe([AccessPermissions::STAFF_INVITE, PlatformPermissions::STORE_UPDATE]);
    });

    it('refuses staff who do not manage roles', function () {
        Fx::actAsStaff(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['*']));

        expect(fn () => newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]))->toThrow(Unauthorized::class);
    });

    it('keeps saved role names unique in each language, ignoring case — refused in code before the database', function (string $nameAr, string $nameEn) {
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);
        newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE], 'Support', 'الدعم');

        withoutNameIndexes();

        expect(fn () => newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE], $nameEn, $nameAr))->toThrow(RoleNameTaken::class);
    })->with([
        'the English name in other letters' => ['دعم آخر', 'SUPPORT'],
        'the Arabic name' => ['الدعم', 'Help desk'],
    ]);

    it('grants, in a queued job, only what the person who queued it holds', function () {
        $adminId = Fx::staffWith(ROLE_ADMIN_ACTIONS, ['*'], RoleLevel::Admin);
        // Inside a job, Platform makes the actor the system on behalf of whoever queued it.
        $jobs = app(JobActorState::class);
        $jobs->enter(1, Actor::system(Actor::staff($adminId)));

        try {
            expect(fn () => newRole(RoleLevel::Staff, [PlatformPermissions::SETTINGS_UPDATE]))->toThrow(PermissionEscalation::class)
                ->and(fn () => newRole(RoleLevel::Admin, [PlatformPermissions::STORE_UPDATE]))->toThrow(SuperAdminOnly::class);
            expect(newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]))->not->toBe('');
        } finally {
            $jobs->leave(1);
        }
    });
});

describe('cloning a role', function () {
    it('copies a saved role at this moment, never linked to it', function () {
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);
        $sourceId = newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);

        $cloneId = app(CloneRoleHandler::class)->handle(new CloneRole($sourceId, 'نسخة', 'Copy'));
        app(UpdateRoleHandler::class)->handle(new UpdateRole($sourceId, permissions: [PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]));

        expect(Fx::rolePermissions($cloneId))->toBe([PlatformPermissions::STORE_UPDATE])
            ->and(DB::table('platform.audit_entries')->where('subject_id', $cloneId)->value('changes'))->toContain($sourceId);
    });

    it('refuses to clone a role holding an action the author does not hold', function () {
        $sourceId = Fx::role([PlatformPermissions::SETTINGS_UPDATE]);
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);

        expect(fn () => app(CloneRoleHandler::class)->handle(new CloneRole($sourceId, 'نسخة', 'Copy')))->toThrow(PermissionEscalation::class);
    });

    it('lets only a Super Admin clone an admin role', function () {
        $sourceId = Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin);
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);

        expect(fn () => app(CloneRoleHandler::class)->handle(new CloneRole($sourceId, 'نسخة', 'Copy')))->toThrow(SuperAdminOnly::class);
    });

    it('refuses a name another saved role has, in code before the database', function () {
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);
        $sourceId = newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE], 'Support', 'الدعم');

        withoutNameIndexes();

        expect(fn () => app(CloneRoleHandler::class)->handle(new CloneRole($sourceId, 'نسخة', 'support')))->toThrow(RoleNameTaken::class);
    });

    it('never clones a personal role', function () {
        $personalId = Fx::personalRole(Fx::staff(), [PlatformPermissions::STORE_UPDATE]);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(CloneRoleHandler::class)->handle(new CloneRole($personalId, 'نسخة', 'Copy')))->toThrow(RoleNotFound::class);
    });
});

describe('editing a saved role', function () {
    it('changes it for everyone who holds it, each in their own stores, and their cached permissions at once', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $ksa = Fx::staff();
        $uae = Fx::staff();
        Fx::assign($ksa, $roleId, ['sa']);
        Fx::assign($uae, $roleId, ['ae']);
        Fx::warmCache($ksa);
        Fx::warmCache($uae);
        Fx::actAsAdmin(['sa', 'ae'], [...ROLE_ADMIN_ACTIONS, PlatformPermissions::SETTINGS_UPDATE]);

        app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'Store keepers', permissions: [PlatformPermissions::STORE_UPDATE, PlatformPermissions::SETTINGS_UPDATE]));

        expect(Fx::rolePermissions($roleId))->toBe([PlatformPermissions::SETTINGS_UPDATE, PlatformPermissions::STORE_UPDATE])
            ->and(DB::table('access.roles')->where('id', $roleId)->value('name'))->toContain('Store keepers')
            ->and(Fx::audits('access.role.updated', $roleId))->toBe(1);

        // The added action reaches each holder in their own stores, and only there.
        Fx::actAsStaff($ksa);
        expect(Fx::storeCodesWith(PlatformPermissions::SETTINGS_UPDATE))->toBe(['sa']);
        Fx::actAsStaff($uae);
        expect(Fx::storeCodesWith(PlatformPermissions::SETTINGS_UPDATE))->toBe(['ae']);
    });

    it('takes a removed action away from every holder at once', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]);
        $holderId = Fx::staff();
        Fx::assign($holderId, $roleId, ['sa']);
        Fx::warmCache($holderId);
        Fx::actAsAdmin(['sa'], ROLE_ADMIN_ACTIONS);

        app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, permissions: [PlatformPermissions::STORE_UPDATE]));

        Fx::actAsStaff($holderId);
        expect(Fx::storeCodesWith(PlatformPermissions::MEDIA_UPLOAD))->toBe([]);
    });

    it('refuses what a role cannot hold, even on a role nobody holds', function (array $permissions, string $error) {
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);
        $roleId = newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, permissions: Fx::names($permissions))))->toThrow($error)
            ->and(Fx::rolePermissions($roleId))->toBe([PlatformPermissions::STORE_UPDATE]);
    })->with([
        'an undeclared action' => [['catalog.product.update'], UnknownPermission::class],
        'a Super Admin action' => [[PlatformPermissions::STORE_CREATE], ReservedPermission::class],
        'a management action in a staff role' => [[AccessPermissions::STAFF_ASSIGN_ROLE], AdminOnlyPermission::class],
        'an action the author does not hold' => [[PlatformPermissions::SETTINGS_UPDATE], PermissionEscalation::class],
    ]);

    it('is refused when a holder is in a store the author does not cover, and allowed when all are covered', function () {
        $wide = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $narrow = Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::assign(Fx::staff(), $wide, ['sa', 'ae']);
        Fx::assign(Fx::staff(), $narrow, ['sa']);
        Fx::actAsAdmin(['sa'], ROLE_ADMIN_ACTIONS);

        app(UpdateRoleHandler::class)->handle(new UpdateRole($narrow, nameEn: 'KSA keepers'));

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($wide, nameEn: 'Other')))->toThrow(Unauthorized::class);
    });

    it('needs the right to assign roles in every holder\'s store, not only to manage roles (owner, 2026-09-19)', function () {
        $held = Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::assign(Fx::staff(), $held, ['sa']);
        Fx::actAsAdmin(['sa'], [AccessPermissions::ROLE_MANAGE, PlatformPermissions::STORE_UPDATE]);

        $unheld = newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);
        app(UpdateRoleHandler::class)->handle(new UpdateRole($unheld, nameEn: 'Renamed'));

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($held, nameEn: 'Other')))->toThrow(Unauthorized::class, AccessPermissions::STAFF_ASSIGN_ROLE);
    });

    it('is refused when an action would reach a holder\'s store where the author does not hold it', function () {
        $roleId = Fx::role([PlatformPermissions::MEDIA_UPLOAD]);
        Fx::assign(Fx::staff(), $roleId, ['sa', 'eg']);
        // Covers both stores, but holds store.update only in KSA.
        Fx::actAsAdmin(['sa', 'eg'], [...ROLE_ADMIN_ACTIONS], [PlatformPermissions::STORE_UPDATE => ['sa']]);

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, permissions: [PlatformPermissions::MEDIA_UPLOAD, PlatformPermissions::STORE_UPDATE])))
            ->toThrow(PermissionEscalation::class)
            ->and(Fx::rolePermissions($roleId))->toBe([PlatformPermissions::MEDIA_UPLOAD]);
    });

    it('drops the holders\' exceptions for an action taken out of the role, and audits it', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::SETTINGS_UPDATE]);
        $holderId = Fx::staff();
        Fx::assign($holderId, $roleId, ['sa', 'eg'], [PlatformPermissions::SETTINGS_UPDATE => ['sa']]);
        $audits = Fx::audits('access.staff_user.role_changed', $holderId);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, permissions: [PlatformPermissions::STORE_UPDATE]));

        expect(DB::table('access.role_assignment_exceptions')->where('staff_user_id', $holderId)->count())->toBe(0)
            ->and(Fx::audits('access.staff_user.role_changed', $holderId))->toBe($audits + 1);
    });

    it('lets only a Super Admin edit an admin role', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin);
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'Other')))->toThrow(SuperAdminOnly::class);
    });

    it('refuses to empty a role, and to edit a personal role here', function () {
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);
        $roleId = newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);
        $personalId = Fx::personalRole(Fx::staff(), [PlatformPermissions::STORE_UPDATE]);

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, permissions: [])))->toThrow(InvalidAccessAttribute::class, 'at least one action')
            ->and(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($personalId, nameEn: 'Other')))->toThrow(InvalidAccessAttribute::class, "staff member's page");
    });

    it('refuses a name another saved role has, in code before the database, but not its own', function () {
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);
        newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE], 'Support', 'الدعم');
        $roleId = newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE], 'Sales', 'المبيعات');

        app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'SALES'));

        withoutNameIndexes();

        expect(fn () => app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'support')))->toThrow(RoleNameTaken::class);
    });

    it('leaves a name no module declares alone: an edit still works, and a clone leaves it behind', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        DB::table('access.role_permissions')->insert(['role_id' => $roleId, 'permission' => 'catalog.product.update']);
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);

        app(UpdateRoleHandler::class)->handle(new UpdateRole($roleId, nameEn: 'Renamed'));
        $cloneId = app(CloneRoleHandler::class)->handle(new CloneRole($roleId, 'نسخة', 'Copy'));

        expect(Fx::rolePermissions($roleId))->toBe(['catalog.product.update', PlatformPermissions::STORE_UPDATE])
            ->and(Fx::rolePermissions($cloneId))->toBe([PlatformPermissions::STORE_UPDATE]);
    });

    it('never lets a limited admin hand out a name no module declares', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        DB::table('access.role_permissions')->insert(['role_id' => $roleId, 'permission' => 'catalog.product.update']);
        $staffId = Fx::staff();
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);

        // It would come back to life with its module, and the admin never held it.
        expect(fn () => app(ChangeStaffRoleHandler::class)->handle(Fx::change($staffId, ['sa'], savedRoleId: $roleId)))->toThrow(UnknownPermission::class);
    });
});

describe('deleting a saved role', function () {
    it('deletes a role nobody holds', function () {
        Fx::actAsAdmin(['*'], ROLE_ADMIN_ACTIONS);
        $roleId = newRole(RoleLevel::Staff, [PlatformPermissions::STORE_UPDATE]);

        app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId));

        expect(DB::table('access.roles')->where('id', $roleId)->exists())->toBeFalse()
            ->and(Fx::rolePermissions($roleId))->toBe([])
            ->and(Fx::audits('access.role.deleted', $roleId))->toBe(1);
    });

    it('needs a replacement when someone holds it, naming them, and moves every holder to it', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::MEDIA_UPLOAD]);
        $replacementId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $holderId = Fx::staff(firstName: 'Khalid');
        Fx::assign($holderId, $roleId, ['sa']);
        Fx::warmCache($holderId);
        Fx::actAsAdmin(['sa'], ROLE_ADMIN_ACTIONS);

        try {
            app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId));
            $refused = null;
        } catch (RoleInUse $e) {
            $refused = $e;
        }

        expect($refused?->context())->toBe(['count' => 1, 'holders' => 'Khalid Member']);

        app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId, $replacementId));

        expect(Fx::roleOf($holderId))->toBe($replacementId)
            ->and(DB::table('access.roles')->where('id', $roleId)->exists())->toBeFalse()
            ->and(Fx::audits('access.staff_user.role_changed', $holderId))->toBe(2);

        // The holder's cached permissions follow the move at once.
        Fx::actAsStaff($holderId);
        expect(Fx::storeCodesWith(PlatformPermissions::MEDIA_UPLOAD))->toBe([]);
    });

    it('moves holders to a replacement that still holds a name no module declares', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $replacementId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        DB::table('access.role_permissions')->insert(['role_id' => $replacementId, 'permission' => 'catalog.product.update']);
        $holderId = Fx::staff();
        Fx::assign($holderId, $roleId, ['sa']);
        // A Super Admin: a limited author is refused such a replacement by requireCovers, because
        // the name would come back to life with its module.
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        // A name left behind by a switched-off module grants nothing and is passed over, exactly
        // as an edit passes over it: the delete is not the place to refuse it (review of step 7).
        app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId, $replacementId));

        expect(Fx::roleOf($holderId))->toBe($replacementId);
    });

    it('refuses a limited author that same replacement: the dormant name is not theirs to hand out', function () {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $replacementId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        DB::table('access.role_permissions')->insert(['role_id' => $replacementId, 'permission' => 'catalog.product.update']);
        $holderId = Fx::staff();
        Fx::assign($holderId, $roleId, ['sa']);
        Fx::actAsAdmin(['sa'], ROLE_ADMIN_ACTIONS);

        // The delete moves people onto a role they never held, so the author must cover every
        // action of it; a name no module declares cannot be covered (review of step 7).
        expect(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId, $replacementId)))
            ->toThrow(UnknownPermission::class)
            ->and(Fx::roleOf($holderId))->toBe($roleId);
    });

    it('refuses a replacement of another level, a personal role, or the role itself', function (Closure $replacement) {
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::assign(Fx::staff(), $roleId, ['sa']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId, $replacement($roleId))))->toThrow(InvalidAccessAttribute::class, 'same level');
    })->with([
        'an admin role for staff' => [fn (string $roleId) => Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin)],
        'a personal role' => [fn (string $roleId) => Fx::personalRole(Fx::staff(), [PlatformPermissions::STORE_UPDATE])],
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
        Fx::actAsAdmin(['sa'], ROLE_ADMIN_ACTIONS);

        expect(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($roleId, $replacementId)))->toThrow(PermissionEscalation::class)
            ->and(DB::table('access.roles')->where('id', $roleId)->exists())->toBeTrue()
            ->and(Fx::audits('access.role.deleted', $roleId))->toBe(0);
    });

    it('lets only a Super Admin delete an admin role, and only an admin covering every holder delete a staff role', function () {
        $adminRole = Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin);
        $staffRole = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $replacement = Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::assign(Fx::staff(), $staffRole, ['ae']);
        Fx::actAsAdmin(['sa'], ROLE_ADMIN_ACTIONS);

        expect(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($adminRole)))->toThrow(SuperAdminOnly::class)
            ->and(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($staffRole, $replacement)))->toThrow(Unauthorized::class);
    });

    it('never deletes a personal role here', function () {
        $personalId = Fx::personalRole(Fx::staff(), [PlatformPermissions::STORE_UPDATE]);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(DeleteRoleHandler::class)->handle(new DeleteRole($personalId)))->toThrow(InvalidAccessAttribute::class, 'personal role');
    });
});
