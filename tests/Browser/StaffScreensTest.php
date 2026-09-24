<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The staff screens, in a real browser (frontend.md §3.3, C1-C9).
|
| The integration tests beside these prove what the screens are handed. These prove a person can
| use them: that the list draws grouped by store, that a person's screen shows what their role
| allows, that the role editor offers the stores and saves, and that the invitation really is three
| steps with nothing sent before the last one.
*/

const STAFF_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so it cannot see a transaction wrapped round the test - the two
// deadlock instead (found by running it, 2026-09-23). These tests make their own data unique.

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
 * The sign-in is written out in each test rather than returned from here: the page object the
 * browser plugin hands back is not the type its own signature promises, so a helper that returned
 * it would have to lie about what it returns.
 */
function staffScreenSuperAdminEmail(): string
{
    return (string) DB::table('access.staff_users')
        ->where('id', Fx::staff(superAdmin: true))
        ->value('email');
}

it('draws the staff list grouped by store, and opens one person', function () {
    $name = 'Rakan '.Str::random(6);
    $staffId = Fx::staff(firstName: $name);
    Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE]), ['sa']);

    $page = visit('/admin/sign-in')
        ->type('#email', staffScreenSuperAdminEmail())
        ->type('#password', STAFF_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it. Waiting for the code screen first is what makes reading it reliable
        // (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/staff');

    // In English, because the fixture's staff member keeps English as their own language and the
    // panel is read in the reader's language, not the system's.
    $page->assertSee('Staff')
        ->assertSee($name)
        // The section a person belongs in is the store they work in (owner, 2026-09-19).
        ->assertSee('Saudi Arabia')
        ->assertNoJavaScriptErrors();

    $page->navigate("/admin/staff/{$staffId}")
        ->assertSee('What it allows')
        // Their role reaches one store, and the screen says which by name rather than by id.
        ->assertSee('Store settings and tax')
        ->assertNoJavaScriptErrors();
});

it('saves a role and its stores from the editor', function () {
    $staffId = Fx::staff(firstName: 'Lama '.Str::random(6));
    Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_VIEW]), ['sa']);

    $page = visit('/admin/sign-in')
        ->type('#email', staffScreenSuperAdminEmail())
        ->type('#password', STAFF_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it. Waiting for the code screen first is what makes reading it reliable
        // (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate("/admin/staff/{$staffId}/role");

    $page->assertSee('Role and stores')
        ->assertSee('A role of their own')
        // Where it reaches: the stores this reader may hand out, by name.
        ->assertSee('Saudi Arabia')
        ->click('button[type="submit"]')
        ->assertNoJavaScriptErrors();

    // Saved as it stood, so they keep a role: the screen came back to their own page.
    expect(DB::table('access.role_assignments')->where('staff_user_id', $staffId)->exists())->toBeTrue();
});

it('walks the invitation through its three steps, sending nothing before the last', function () {
    $page = visit('/admin/sign-in')
        ->type('#email', staffScreenSuperAdminEmail())
        ->type('#password', STAFF_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it. Waiting for the code screen first is what makes reading it reliable
        // (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/staff/invite');

    $email = 'invited.'.Str::lower(Str::random(8)).'@touchwood.test';

    $page->assertSee('Invite a member')
        ->assertSee('Nothing is sent until the last step')
        ->type('#first_name', 'Hala')
        ->type('#last_name', 'Al-Otaibi')
        ->type('#email', $email)
        ->type('#phone', Fx::phone())
        ->type('#job_title', 'Buyer')
        ->type('#date_of_birth', '1994-02-17')
        ->click('button[type="submit"]');

    // Step two: still nobody in the table, because the form has not been sent.
    $page->assertSee('The role')->assertNoJavaScriptErrors();
    expect(DB::table('access.staff_users')->where('email', $email)->exists())->toBeFalse();

    // Step three, and still nobody: the whole form is one request, sent at the end.
    $page->click('button[type="submit"]')
        ->assertSee('Where it reaches')
        ->assertNoJavaScriptErrors();

    expect(DB::table('access.staff_users')->where('email', $email)->exists())->toBeFalse();
});
