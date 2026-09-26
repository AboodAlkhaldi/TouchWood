<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Where a staff member is signed in, and which browsers skip their code (owner, 2026-09-26).
|
| The case it was asked for: an admin lends their account to somebody for a job, and wants it back
| — every session ended, and that machine asking for an SMS code again.
|
| Sessions live in `access.admin_sessions`, and until this feature every row there was written with
| an empty `user_id`, because Laravel fills it from an auth guard this application does not use. The
| first test below is really about that: without the panel's own session driver there is nothing to
| list and nothing to delete by.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A browser signed in completely as this staff member: the password, then the code.
 *
 * Named for this file: a function declared in a Pest file is global to the whole suite.
 */
function sessionsSignIn(string $staffId, bool $trustBrowser = false): AdminBrowser
{
    $browser = new AdminBrowser('10.9.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
        'trust_browser' => $trustBrowser,
    ])->assertRedirect('/admin');

    return $browser;
}

function sessionsStaff(): string
{
    // A role needs at least one action, and this screen needs none of them: every session use case
    // is the own-account permission every staff member holds.
    return Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa'], RoleLevel::Admin);
}

describe('the sessions a staff member has', function () {
    it('writes the staff member onto their session row, which nothing used to', function () {
        $staffId = sessionsStaff();
        sessionsSignIn($staffId);

        // The panel's own driver fills user_id from the session itself. Without it the column is
        // empty and this whole screen is impossible.
        expect(DB::table('access.admin_sessions')->where('user_id', $staffId)->count())->toBeGreaterThan(0);
    });

    it('shows them their own sessions, and marks the one they are reading on', function () {
        $staffId = sessionsStaff();
        $browser = sessionsSignIn($staffId);

        $browser->get('/admin/account?tab=sessions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tab', 'sessions')
                ->has('sessions', 1)
                ->where('sessions.0.isCurrent', true)
                ->where('sessions.0.ipAddress', fn (?string $ip) => $ip !== null)
            );
    });

    it('never shows one staff member another one\'s sessions', function () {
        $mine = sessionsStaff();
        $theirs = sessionsStaff();

        sessionsSignIn($theirs);
        $browser = sessionsSignIn($mine);

        // Two people are signed in; each sees one session, their own.
        $browser->get('/admin/account?tab=sessions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sessions', 1)->where('sessions.0.isCurrent', true));
    });

    it('lists a trusted browser, and never the token behind it', function () {
        $staffId = sessionsStaff();
        $browser = sessionsSignIn($staffId, trustBrowser: true);

        $browser->get('/admin/account?tab=sessions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('trustedBrowsers', 1)
                ->where('trustedBrowsers.0.expiresAt', fn (string $at) => $at !== '')
                // Only the id and the date: what is stored is a hash, and no screen needs it.
                ->missing('trustedBrowsers.0.token')
            );
    });
});

describe('ending them', function () {
    it('signs out one browser without touching the others', function () {
        $staffId = sessionsStaff();
        $first = sessionsSignIn($staffId);
        $second = sessionsSignIn($staffId);

        $rows = DB::table('access.admin_sessions')->where('user_id', $staffId)->pluck('id')->all();
        expect($rows)->toHaveCount(2);

        $page = $second->get('/admin/account?tab=sessions')->assertOk();
        /** @var array<string, mixed> $props */
        $props = $page->viewData('page')['props'];
        /** @var list<array<string, mixed>> $sessions */
        $sessions = $props['sessions'];
        $other = collect($sessions)->firstWhere('isCurrent', false);

        // The other browser's row, not this one's: the test would prove nothing if it ended the
        // session it is reading from.
        expect($other)->toBeArray();
        $otherId = is_array($other) && is_string($other['id'] ?? null) ? $other['id'] : '';

        $second->post('/admin/account/sessions/'.$otherId)->assertRedirect('/admin/account?tab=sessions');

        expect(DB::table('access.admin_sessions')->where('user_id', $staffId)->count())->toBe(1)
            // And the browser it belonged to is signed out: its row is gone, so its cookie names
            // a session that no longer exists.
            ->and($first->get('/admin')->getStatusCode())->toBe(302);
    });

    it('cannot end a session belonging to somebody else', function () {
        $mine = sessionsStaff();
        $theirs = sessionsStaff();

        sessionsSignIn($theirs);
        $victim = (string) DB::table('access.admin_sessions')->where('user_id', $theirs)->value('id');

        $browser = sessionsSignIn($mine);
        $browser->post('/admin/account/sessions/'.$victim);

        // Still there: the handler takes the asker's id as well as the session's.
        expect(DB::table('access.admin_sessions')->where('id', $victim)->count())->toBe(1);
    });

    it('signs out everywhere, including the browser that pressed it, and untrusts every browser', function () {
        $staffId = sessionsStaff();
        $elsewhere = sessionsSignIn($staffId, trustBrowser: true);
        $here = sessionsSignIn($staffId, trustBrowser: true);

        expect(DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->count())->toBe(2);

        $version = (int) DB::table('access.staff_users')->where('id', $staffId)->value('session_version');

        $here->post('/admin/account/sessions/all')->assertRedirect('/admin/account?tab=sessions');

        expect(DB::table('access.admin_sessions')->where('user_id', $staffId)->count())->toBe(0)
            // Every trusted browser must ask for a code again — the point of the whole feature.
            ->and(DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->count())->toBe(0)
            // And the version moves on, which is what reaches a browser this process cannot see.
            ->and((int) DB::table('access.staff_users')->where('id', $staffId)->value('session_version'))->toBe($version + 1);

        // Both browsers are out, the one that pressed it included.
        expect($elsewhere->get('/admin')->getStatusCode())->toBe(302)
            ->and($here->get('/admin')->getStatusCode())->toBe(302);
    });

    it('lets them sign in again afterwards, which is the next thing anybody does', function () {
        // The test that was missing. The one above proved they were signed out and stopped there,
        // so it never saw that they could not get back in: a session is checked against the
        // **cached** grants, and raising the version on the row without refreshing the cache left
        // the two disagreeing, so every later sign-in was accepted and then thrown out on its very
        // next request. The owner met it as a sign-in that would not stick (2026-09-26).
        $staffId = sessionsStaff();
        $browser = sessionsSignIn($staffId);

        $browser->post('/admin/account/sessions/all')->assertRedirect('/admin/account?tab=sessions');

        // A fresh browser, the whole way in.
        $again = sessionsSignIn($staffId);

        // And it stays in: the panel answers rather than sending them back to the door.
        $again->get('/admin/account?tab=sessions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sessions', 1));
    });
});

describe('trusted browsers', function () {
    it('forgets one, so that browser asks for a code again', function () {
        $staffId = sessionsStaff();
        $browser = sessionsSignIn($staffId, trustBrowser: true);

        $id = (string) DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->value('id');

        $browser->post('/admin/account/trusted-browsers/'.$id)->assertRedirect('/admin/account?tab=sessions');

        expect(DB::table('access.staff_trusted_browsers')->where('id', $id)->count())->toBe(0)
            // It signs nobody out: a trusted browser is one allowed past the code, not one kept
            // signed in.
            ->and(DB::table('access.admin_sessions')->where('user_id', $staffId)->count())->toBe(1);
    });

    it('forgets all of them at once', function () {
        $staffId = sessionsStaff();
        sessionsSignIn($staffId, trustBrowser: true);
        $browser = sessionsSignIn($staffId, trustBrowser: true);

        $browser->post('/admin/account/trusted-browsers')->assertRedirect('/admin/account?tab=sessions');

        expect(DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->count())->toBe(0);
    });

    it('cannot forget a browser belonging to somebody else', function () {
        $mine = sessionsStaff();
        $theirs = sessionsStaff();

        sessionsSignIn($theirs, trustBrowser: true);
        $victim = (string) DB::table('access.staff_trusted_browsers')->where('staff_user_id', $theirs)->value('id');

        $browser = sessionsSignIn($mine);
        $browser->post('/admin/account/trusted-browsers/'.$victim);

        expect(DB::table('access.staff_trusted_browsers')->where('id', $victim)->count())->toBe(1);
    });
});
