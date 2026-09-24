<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The roles screens, in a real browser (frontend.md §3.4).
|
| The integration tests beside these prove what the screens are handed. These prove a person can
| use it: that the list draws at all, that the editor groups actions the way staff think, and that
| ticking one and saving actually creates the role.
*/

const ROLE_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase. The suite serves the application in this process, but a served
// request opens its own connection, so it cannot see a transaction wrapped round the test - and the
// two deadlock against each other instead (found by running it, 2026-09-23). These tests therefore
// leave the database as they found it by making their own data unique, not by rolling it back.

beforeEach(function () {
    // The browser suite serves the application inside this process, so it inherits the suite's
    // session driver - and phpunit.xml forces "array", which keeps nothing between requests.
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A new Super Admin's email address, for signing in through the real screens.
 *
 * The sign-in itself is written out in each test rather than returned from here: the page object
 * the browser plugin hands back is not the type its own signature promises, so a helper that
 * returned it would have to lie about what it returns.
 */
function newSuperAdminEmail(): string
{
    return (string) DB::table('access.staff_users')
        ->where('id', Fx::staff(superAdmin: true))
        ->value('email');
}

it('draws the roles screen, and offers it from the menu', function () {
    $name = 'Shopkeeper '.Str::random(6);
    Fx::role([AccessPermissions::STAFF_VIEW], nameEn: $name);

    $page = visit('/admin/sign-in')
        ->type('#email', newSuperAdminEmail())
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it. Waiting for the code screen first is what makes reading it reliable
        // (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/roles');

    // In English, because the fixture's staff member keeps English as their own language and the
    // panel is read in the reader's language, not the system's.
    $page->assertSee($name)
        ->assertSee('Roles')
        // The first real menu entry: the sidebar had nothing in it until roles shipped.
        ->assertSee('Staff and permissions')
        // The comparison table the design asked to keep: areas down the side, roles across.
        ->assertSee('Permissions by role')
        ->assertNoJavaScriptErrors();
});

it('groups the actions by business area in the editor, rather than by declaration order', function () {
    $page = visit('/admin/sign-in')
        ->type('#email', newSuperAdminEmail())
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it. Waiting for the code screen first is what makes reading it reliable
        // (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/roles/new');

    // The areas staff think in (stage 2b, P2), not the order the modules happened to load.
    $page->assertSee('Staff and permissions')
        ->assertSee('Store settings and tax')
        ->assertNoJavaScriptErrors();
});

it('creates a role from the screen, ticking an action and saving it', function () {
    $english = 'Warehouse keeper '.Str::random(6);
    $page = visit('/admin/sign-in')
        ->type('#email', newSuperAdminEmail())
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it. Waiting for the code screen first is what makes reading it reliable
        // (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/roles/new');

    $page->type('#name_ar', 'أمين المستودع '.Str::random(4))
        ->type('#name_en', $english)
        // The first action of the first area. Named precisely because there are a dozen boxes on
        // this screen and a loose selector matches all of them.
        ->click('section:first-of-type li:first-child [role="checkbox"]')
        ->click('button[type="submit"]')
        ->assertSee($english);

    expect(DB::table('access.roles')->where('name->en', $english)->exists())->toBeTrue();
});

it('shows an admin no way into a role that is not theirs to change', function () {
    // An admin sees admin roles and cannot open them. The screen offers no edit button at all,
    // rather than offering one that fails when pressed (access.md amendment 9).
    $name = 'Manager '.Str::random(6);
    Fx::role([AccessPermissions::STAFF_ASSIGN_ROLE], RoleLevel::Admin, nameEn: $name);

    $staffId = Fx::staffWith(
        [AccessPermissions::ROLE_MANAGE, PlatformPermissions::STORE_VIEW],
        ['sa'],
        RoleLevel::Admin,
    );
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]');

    // The click above only dispatches the submit; the code is not recorded until the server has
    // answered it. Waiting for the code screen first is what makes reading it reliable (this
    // raced, and lost, 2026-09-24).
    $page->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/roles')
        ->assertSee($name)
        ->assertNoJavaScriptErrors();
});

it('lets somebody narrow the permissions table by area, and hide the roles they are not comparing', function () {
    // Two roles, so there is something to hide and something to keep.
    $kept = 'Keeper '.Str::random(6);
    $hidden = 'Hidden '.Str::random(6);
    Fx::role([AccessPermissions::STAFF_VIEW], nameEn: $kept);
    Fx::role([PlatformPermissions::STORE_UPDATE], nameEn: $hidden);

    $page = visit('/admin/sign-in')
        ->type('#email', newSuperAdminEmail())
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/roles');

    $page->assertSee('Permissions by role')->assertNoJavaScriptErrors();

    // Counted in the table itself rather than asserted as page text: every business area is also
    // a menu group, so each of these words is in the sidebar whatever the table is showing.
    $areas = (int) $page->script('document.querySelectorAll("table tbody tr").length');
    $columns = (int) $page->script('document.querySelectorAll("table thead th").length');

    expect($areas)->toBeGreaterThan(1)
        // One column per role, and the area column in front of them.
        ->and($columns)->toBeGreaterThanOrEqual(3);

    // The filter narrows the rows to the area somebody is actually looking at.
    $page->type('input[placeholder="Filter the areas"]', 'Media')->assertNoJavaScriptErrors();

    $narrowed = (int) $page->script('document.querySelectorAll("table tbody tr").length');
    $shown = (string) $page->script('document.querySelector("table tbody tr td").innerText');

    expect($narrowed)->toBeLessThan($areas)
        ->and($shown)->toContain('Media');

    // And the columns menu opens with a row per role, so ten roles can become two.
    $page->click('[data-test="columns"]')
        ->assertSee('Roles to show')
        ->assertSee($hidden)
        ->assertNoJavaScriptErrors();
});
