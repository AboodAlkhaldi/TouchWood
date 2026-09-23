<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Query\ListRoles\ListRolesHandler;
use Modules\Access\Application\Query\ListStaff\ListStaffHandler;
use Modules\Access\Application\Query\RoleEditorPermissions\RoleEditorPermissionsHandler;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Presentation\Http\Resource\StaffActionRow;
use Modules\Access\Presentation\Http\Resource\StaffPages;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function staffPages(): StaffPages
{
    return app(StaffPages::class);
}

/**
 * One action on somebody's screen. Absent means this test's own premise is wrong, so it says so
 * rather than failing later on a property of null.
 */
function shownAction(string $staffId, string $name): StaffActionRow
{
    $page = staffPages()->member($staffId);

    return collect($page->actions)->firstWhere('name', $name)
        ?? throw new RuntimeException("{$staffId}'s screen does not show {$name}.");
}

/**
 * Stage 2b, step 2. What the staff screens are handed (frontend.md §3.3, C1–C9).
 *
 * Who may see somebody, and what may be done to them, are Access's answers and are tested where
 * they are decided. What is tested here is the arrangement the screens rely on — the section a
 * person belongs in, the stores an action really reaches, the roles that may be given — and that
 * none of it quietly widens what Access allowed.
 */
describe('what the staff screens are given', function () {
    it('puts each person in their own store, and anybody with two in Centralized', function () {
        $riyadh = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);
        $both = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa', 'eg']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $page = staffPages()->list(app(ListStaffHandler::class), null, null);
        $sections = [];

        foreach ($page->groups as $group) {
            foreach ($group->staff as $person) {
                $sections[$person->id] = $group->key;
            }
        }

        expect($sections[$riyadh])->toBe(Fx::storeId('sa'))
            ->and($sections[$both])->toBe(StaffPages::CENTRALIZED);
    });

    it('names the stores an action reaches, and marks the one given stores of its own', function () {
        // The role reaches two stores; one action inside it reaches only one (access.md §1.5).
        $staffId = Fx::staff();
        Fx::assign($staffId, Fx::role([AccessPermissions::STAFF_VIEW, PlatformPermissions::STORE_UPDATE]), ['sa', 'eg'], [
            PlatformPermissions::STORE_UPDATE => ['eg'],
        ]);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $follows = shownAction($staffId, AccessPermissions::STAFF_VIEW);
        $apart = shownAction($staffId, PlatformPermissions::STORE_UPDATE);

        expect($follows->exception)->toBeFalse()
            ->and($follows->storeNames)->toHaveCount(2)
            ->and($apart->exception)->toBeTrue()
            ->and($apart->storeNames)->toBe(['مصر']);
    });

    it('offers a role editor only the stores the reader may hand out', function () {
        // An admin of Riyadh alone cannot give anybody Cairo, so Cairo is not on the screen at all.
        $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_VIEW, AccessPermissions::STAFF_ASSIGN_ROLE]);

        $page = staffPages()->roleEditor(app(ListRolesHandler::class), app(RoleEditorPermissionsHandler::class), $staffId);

        expect(array_column($page->stores, 'id'))->toBe([Fx::storeId('sa')]);
    });

    it('gives the editor the actions each saved role holds, so an edit can be told from an untouched role', function () {
        // Saving an edited saved role makes it this person's own (access.md §1.5), which the screen
        // can only notice if it knows what the saved one held.
        $role = Fx::role([AccessPermissions::STAFF_VIEW, PlatformPermissions::STORE_UPDATE]);
        $staffId = Fx::staff();
        Fx::assign($staffId, $role, ['sa']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $page = staffPages()->roleEditor(app(ListRolesHandler::class), app(RoleEditorPermissionsHandler::class), $staffId);

        expect($page->savedPermissions[$role] ?? [])->toEqualCanonicalizing([
            AccessPermissions::STAFF_VIEW,
            PlatformPermissions::STORE_UPDATE,
        ])->and($page->personal)->toBeFalse()
            ->and($page->roleId)->toBe($role);
    });

    it('says a role is their own when nobody else holds it', function () {
        $staffId = Fx::staff();
        Fx::personalRole($staffId, [AccessPermissions::STAFF_VIEW]);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $page = staffPages()->roleEditor(app(ListRolesHandler::class), app(RoleEditorPermissionsHandler::class), $staffId);

        // Their own role is not one of the saved ones, so the list does not offer it back to them.
        expect($page->personal)->toBeTrue()
            ->and(array_column($page->savedRoles, 'id'))->not->toContain($page->roleId)
            ->and($page->chosen)->toBe([AccessPermissions::STAFF_VIEW]);
    });

    it('offers a staff member the roles of their own level, and no admin role', function () {
        $adminRole = Fx::role([AccessPermissions::STAFF_VIEW], RoleLevel::Admin);
        $staffRole = Fx::role([AccessPermissions::STAFF_VIEW]);
        $staffId = Fx::staff();
        Fx::assign($staffId, $staffRole, ['sa']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $page = staffPages()->roleEditor(app(ListRolesHandler::class), app(RoleEditorPermissionsHandler::class), $staffId);
        $offered = array_column($page->savedRoles, 'id');

        expect($offered)->toContain($staffRole)
            ->and($offered)->not->toContain($adminRole);
    });

    it('refuses the role editor to somebody who may read the staff list but not hand out a role', function () {
        $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_VIEW]);

        // Access's refusal, before anything is looked up: an editor that could only refuse on save
        // is worse than no editor at all.
        expect(fn () => staffPages()->roleEditor(app(ListRolesHandler::class), app(RoleEditorPermissionsHandler::class), $staffId))
            ->toThrow(Unauthorized::class);
    });

    it('shows an admin the actions an admin may hold only when a Super Admin is inviting', function () {
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_ASSIGN_ROLE]);

        $page = staffPages()->invite(app(ListRolesHandler::class), app(RoleEditorPermissionsHandler::class));

        // Nobody but a Super Admin brings in an admin (access.md §1.6), so nobody else is shown
        // what an admin's role could hold.
        expect($page->maySetAdmin)->toBeFalse()
            ->and($page->adminPermissions)->toBe([])
            ->and($page->permissions)->not->toBe([]);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $forSuperAdmin = staffPages()->invite(app(ListRolesHandler::class), app(RoleEditorPermissionsHandler::class));

        expect($forSuperAdmin->maySetAdmin)->toBeTrue()
            ->and($forSuperAdmin->adminPermissions)->not->toBe([]);
    });

    it('refuses the invitation form to somebody who cannot invite', function () {
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_VIEW, AccessPermissions::STAFF_ASSIGN_ROLE]);

        expect(fn () => staffPages()->invite(app(ListRolesHandler::class), app(RoleEditorPermissionsHandler::class)))
            ->toThrow(Unauthorized::class);
    });
});
