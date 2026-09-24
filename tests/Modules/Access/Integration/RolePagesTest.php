<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\ListRoles\ListRolesHandler;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Application\Query\ViewRole\ViewRoleHandler;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Presentation\Http\Resource\EditorPermissionRow;
use Modules\Access\Presentation\Http\Resource\RoleEditorPage;
use Modules\Access\Presentation\Http\Resource\RolePages;
use Modules\Access\Presentation\Http\Resource\RoleRow;
use Modules\Access\Presentation\Http\Resource\RolesPage;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function rolePages(): RolePages
{
    return app(RolePages::class);
}

/**
 * One role's row in the list. Absent means the test's own premise is wrong, so it says so rather
 * than failing later on a property of null.
 */
function roleRow(RolesPage $page, string $roleId): RoleRow
{
    return collect($page->roles)->firstWhere('id', $roleId)
        ?? throw new RuntimeException("The roles list has no row for {$roleId}.");
}

/**
 * One action the editor offers.
 */
function offeredAction(RoleEditorPage $editor, string $name): EditorPermissionRow
{
    return collect($editor->permissions)->firstWhere('name', $name)
        ?? throw new RuntimeException("The editor does not offer {$name}.");
}

/**
 * Stage 2b, step 2. What the roles screens are handed (frontend.md §3.4, D1–D5).
 *
 * Who may see or change a role is Access's answer, and it is tested where it is decided. What is
 * tested here is the arrangement the screens rely on: an action under the business area staff think
 * in, a holder the reader may actually name, a replacement for a role about to be deleted — and
 * that none of it quietly widens what Access allowed.
 */
describe('what the roles screens are given', function () {
    it('says which business areas each role reaches into, for the comparison table', function () {
        // The table on D1 is areas down the side and roles across the top, so a row needs its
        // areas - a count of actions cannot draw it.
        $role = Fx::role([AccessPermissions::STAFF_VIEW, PlatformPermissions::STORE_UPDATE]);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $row = roleRow(rolePages()->list(app(ListRolesHandler::class)), $role);

        expect($row->groups)->toContain('staff_and_permissions')
            ->and($row->groups)->toContain('store_settings')
            ->and($row->editable)->toBeTrue();
    });

    it('reads every role\'s actions in one go, not one query per role', function () {
        // A list screen that asks per row is invisible with five roles and painful with fifty. What
        // matters is not how many reads it takes but that the number does not grow with the roles,
        // so that is what is measured: the same work for two roles as for eight.
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $count = function (): int {
            $reads = 0;
            DB::listen(function () use (&$reads): void {
                $reads++;
            });
            rolePages()->list(app(ListRolesHandler::class));

            return $reads;
        };

        Fx::role([AccessPermissions::STAFF_VIEW]);
        Fx::role([PlatformPermissions::STORE_UPDATE]);

        // Once to warm whatever is cached for the life of a request, so the two measurements below
        // are of the same thing.
        $count();
        $forTwo = $count();

        foreach ([
            PlatformPermissions::AUDIT_VIEW,
            PlatformPermissions::STORE_VIEW,
            PlatformPermissions::SETTINGS_VIEW,
            PlatformPermissions::SETTINGS_UPDATE,
            AccessPermissions::CUSTOMER_VIEW,
            PlatformPermissions::MEDIA_UPLOAD,
        ] as $permission) {
            Fx::role([$permission]);
        }

        expect($count())->toBe($forTwo);
    });

    it('offers the actions the author holds, and shows the rest without letting them be ticked', function () {
        // Nobody hands out what they do not have. It is shown rather than hidden so an admin can
        // see the shape of the system, and why something is not theirs to give (access.md §1.5).
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::ROLE_MANAGE, AccessPermissions::STAFF_VIEW], ['sa'], RoleLevel::Admin));

        $editor = rolePages()->editor(app(RoleEditorPermissionsHandler::class), RoleLevel::Staff);
        $held = offeredAction($editor, AccessPermissions::STAFF_VIEW);
        $notHeld = offeredAction($editor, PlatformPermissions::AUDIT_VIEW);

        expect($held->grantable)->toBeTrue()
            // Its business area travels with it, so the editor can group without asking again.
            ->and($held->group)->toBe('staff_and_permissions')
            ->and($notHeld->grantable)->toBeFalse();
    });

    it('tells an admin that an admin role is not theirs to change', function () {
        // Only a Super Admin makes or changes an admin role (amendment 9). The screen shows it and
        // offers no way in; `editable` is Access's answer, not the screen's opinion.
        $adminRole = Fx::role([AccessPermissions::STAFF_ASSIGN_ROLE], RoleLevel::Admin);
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::ROLE_MANAGE], ['sa'], RoleLevel::Admin));

        $row = roleRow(rolePages()->list(app(ListRolesHandler::class)), $adminRole);

        expect($row->editable)->toBeFalse();
    });

    it('names only the holders the reader manages, and counts them all', function () {
        $role = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $mine = Fx::staff(firstName: 'Mine');
        $theirs = Fx::staff(firstName: 'Theirs');
        Fx::assign($mine, $role, ['sa']);
        Fx::assign($theirs, $role, ['eg']);

        // An admin whose own reach is KSA only. Naming a holder needs the power to assign roles in
        // that holder's stores - the list exists so somebody can act on the people in it, and a
        // reader who could not touch them has no business being shown their names (GrantRules).
        Fx::actAsStaff(Fx::staffWith(
            [AccessPermissions::ROLE_MANAGE, AccessPermissions::STAFF_VIEW, AccessPermissions::STAFF_ASSIGN_ROLE],
            ['sa'],
            RoleLevel::Admin,
        ));

        $page = rolePages()->one(app(ViewRoleHandler::class), app(ListRolesHandler::class), $role);

        // Two people hold it; this reader may name one of them (amendment 8).
        expect($page->holderCount)->toBe(2)
            ->and($page->holders)->toHaveCount(1)
            ->and($page->holders[0]->name)->toContain('Mine')
            // A store is shown by its name, never by the id nobody can read.
            ->and($page->holders[0]->storeNames)->toBe(['السعودية']);
    });

    it('offers somewhere for the holders to go before a role is deleted', function () {
        $role = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $other = Fx::role([PlatformPermissions::STORE_VIEW]);
        $adminRole = Fx::role([AccessPermissions::STAFF_ASSIGN_ROLE], RoleLevel::Admin);
        Fx::assign(Fx::staff(), $role, ['sa']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $page = rolePages()->one(app(ViewRoleHandler::class), app(ListRolesHandler::class), $role);
        $ids = array_map(static fn ($row): string => $row->id, $page->replacements);

        // A saved role of the same level: never this one, and never one of another level, because
        // an admin role and a staff role do not hold the same things.
        expect($ids)->toContain($other)
            ->and($ids)->not->toContain($role)
            ->and($ids)->not->toContain($adminRole);
    });

    it('says how many people a change would reach, before it is made', function () {
        $role = Fx::role([PlatformPermissions::STORE_UPDATE]);
        Fx::assign(Fx::staff(), $role, ['sa']);
        Fx::assign(Fx::staff(), $role, ['sa']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $editor = rolePages()->editorFor(app(ViewRoleHandler::class), app(RoleEditorPermissionsHandler::class), $role);

        // Editing a saved role changes it for everyone holding it; the screen says so first.
        expect($editor->holderCount)->toBe(2)
            ->and($editor->chosen)->toContain(PlatformPermissions::STORE_UPDATE);
    });

    it('shows each role its name in the language the panel is being read in', function () {
        $role = Fx::role([PlatformPermissions::STORE_VIEW], nameEn: 'Shopkeeper');
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        app()->setLocale('en');
        $english = roleRow(rolePages()->list(app(ListRolesHandler::class)), $role);

        app()->setLocale('ar');
        $arabic = roleRow(rolePages()->list(app(ListRolesHandler::class)), $role);

        expect($english->name)->toBe('Shopkeeper')
            ->and($arabic->name)->toBe('دور Shopkeeper');
    });
});
