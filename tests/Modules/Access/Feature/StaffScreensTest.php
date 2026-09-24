<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A session that survives a redirect: phpunit.xml forces "array", which keeps nothing between
    // requests, and every form here is answered with one (Access amendment 12).
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| Stage 2b, step 2 - the staff screens over HTTP (frontend.md §3.3, C1-C9).
|
| Every helper here is named after this file's subject. A function declared in a Pest file is global
| to the whole suite, so two files sharing a name stop every run (project conventions).
*/

/**
 * A browser signed in completely as this staff member: the password, then the code.
 */
function staffScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.6.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

/**
 * An admin of the Saudi store who may do everything these screens offer, signed in.
 */
function staffScreenAdmin(): AdminBrowser
{
    return staffScreenSignIn(Fx::staffWith(
        [
            AccessPermissions::STAFF_VIEW,
            AccessPermissions::STAFF_INVITE,
            AccessPermissions::STAFF_ASSIGN_ROLE,
            PlatformPermissions::STORE_UPDATE,
        ],
        ['sa'],
        RoleLevel::Admin,
    ));
}

describe('the staff screens', function () {
    it('opens the invitation form on its own path, not as somebody whose id is "invite"', function () {
        // GET /admin/staff/{staff} would swallow this one if it were registered first, and an
        // admin would meet "no such person" where the form should be.
        staffScreenAdmin()->get('/admin/staff/invite')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Access/Admin/Staff/Invite')
                ->where('maySetAdmin', false)
                ->has('countries')
                ->has('stores', 1)
            );
    });

    it('invites somebody with a role of their own, and creates nobody before the last step', function () {
        $browser = staffScreenAdmin();

        // The three steps are one request: what the screen sends at the end of them.
        $browser->post('/admin/staff/invite', [
            'email' => 'noura@touchwood.test',
            'first_name' => 'Noura',
            'last_name' => 'Al-Harbi',
            'job_title' => 'Store manager',
            'date_of_birth' => '1995-04-11',
            'country' => 'SA',
            'address' => 'Riyadh',
            'phone' => Fx::phone(),
            'locale' => 'ar',
            'access_level' => 'SELECTED_STORES',
            'store_ids' => [Fx::storeId('sa')],
            'permissions' => [PlatformPermissions::STORE_UPDATE],
            'exceptions' => [],
        ])->assertRedirect();

        // Absent means this test's own premise is wrong, so it says so rather than failing later
        // on a property of null.
        $row = DB::table('access.staff_users')->where('email', 'noura@touchwood.test')->first()
            ?? throw new RuntimeException('The invitation created nobody.');

        expect($row->status)->toBe(StaffStatus::Invited->value);

        // Their role is named after them, so an admin reading the roles list can see whose it is.
        $roleId = Fx::roleOf((string) $row->id);
        expect(Fx::rolePermissions($roleId))->toBe([PlatformPermissions::STORE_UPDATE]);
    });

    it('turns an edited saved role into a role of that person own, leaving the saved one alone', function () {
        $saved = Fx::role([PlatformPermissions::STORE_UPDATE, AccessPermissions::STAFF_VIEW]);
        $staffId = Fx::staff();
        Fx::assign($staffId, $saved, ['sa']);
        $browser = staffScreenAdmin();

        // The same role with one action taken out: the screen sends actions rather than an id,
        // and Access makes it theirs alone (access.md §1.5).
        $browser->post("/admin/staff/{$staffId}/role", [
            'access_level' => 'SELECTED_STORES',
            'store_ids' => [Fx::storeId('sa')],
            'permissions' => [PlatformPermissions::STORE_UPDATE],
            'exceptions' => [],
        ])->assertRedirect("/admin/staff/{$staffId}");

        $now = Fx::roleOf($staffId);

        expect($now)->not->toBe($saved)
            ->and(Fx::rolePermissions($now))->toBe([PlatformPermissions::STORE_UPDATE])
            // The saved role still holds what it always held, for everybody else on it.
            ->and(Fx::rolePermissions($saved))->toEqualCanonicalizing([
                PlatformPermissions::STORE_UPDATE,
                AccessPermissions::STAFF_VIEW,
            ]);
    });

    it('keeps a saved role as it is when nothing was edited', function () {
        $saved = Fx::role([PlatformPermissions::STORE_UPDATE]);
        $staffId = Fx::staff();
        Fx::assign($staffId, Fx::role([AccessPermissions::STAFF_VIEW]), ['sa']);
        $browser = staffScreenAdmin();

        $browser->post("/admin/staff/{$staffId}/role", [
            'saved_role_id' => $saved,
            'access_level' => 'SELECTED_STORES',
            'store_ids' => [Fx::storeId('sa')],
            'exceptions' => [],
        ])->assertRedirect("/admin/staff/{$staffId}");

        expect(Fx::roleOf($staffId))->toBe($saved);
    });
});
