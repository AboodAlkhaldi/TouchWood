<?php

declare(strict_types=1);

use App\Exceptions\QueryErrorLog;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmail;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmailHandler;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdmin;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdminHandler;
use Modules\Access\Application\Command\DisableStaff\DisableStaff;
use Modules\Access\Application\Command\DisableStaff\DisableStaffHandler;
use Modules\Access\Application\Command\EnableStaff\EnableStaff;
use Modules\Access\Application\Command\EnableStaff\EnableStaffHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChange;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChangeHandler;
use Modules\Access\Application\Command\RequestStaffPasswordReset\RequestStaffPasswordReset;
use Modules\Access\Application\Command\RequestStaffPasswordReset\RequestStaffPasswordResetHandler;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhone;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhoneHandler;
use Modules\Access\Application\Command\RevokeSuperAdmin\RevokeSuperAdmin;
use Modules\Access\Application\Command\RevokeSuperAdmin\RevokeSuperAdminHandler;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfile;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfileHandler;
use Modules\Access\Application\Command\VerifyOwnPhoneChange\VerifyOwnPhoneChange;
use Modules\Access\Application\Command\VerifyOwnPhoneChange\VerifyOwnPhoneChangeHandler;
use Modules\Access\Application\Security\Codes;
use Modules\Access\Presentation\Http\Middleware\IdentifyStaff;
use Modules\Access\Presentation\Http\Middleware\UseAdminSession;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\ActorContext;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Illuminate\Support\defer;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

const SIGN_IN_PASSWORD = 'a long enough password';

beforeEach(function () {
    // Sessions live in the database, as in production: the admin session is read again on every
    // request (phpunit.xml's in-memory driver keeps nothing between them here).
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();

    // Who a request acts as, to look at from a test: the pages come with the frontend stage.
    Route::middleware([UseAdminSession::ALIAS, 'web', IdentifyStaff::ALIAS])
        ->get('/admin/_who', fn (ActorContext $actors) => response()->json(['type' => $actors->current()->type->value, 'id' => $actors->current()->id]));
});

/**
 * Who this browser acts as: a staff member's id, or null for a guest. Anything else fails the test —
 * a web request never acts as the system, so "not signed in" must mean a guest.
 */
function signInWho(AdminBrowser $browser): ?string
{
    $who = $browser->get('/admin/_who')->json();
    $who = is_array($who) ? $who : [];
    $type = $who['type'] ?? null;

    if ($type === 'GUEST') {
        return null;
    }

    if ($type !== 'STAFF' || ! is_string($who['id'] ?? null)) {
        throw new LogicException('A web request acted as '.var_export($type, true).', not a staff member or a guest.');
    }

    return $who['id'];
}

function signInEmail(string $staffId): string
{
    return (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
}

/**
 * @return TestResponse<Response>
 */
function signInPassword(AdminBrowser $browser, string $staffId, string $password = SIGN_IN_PASSWORD): TestResponse
{
    return $browser->post('/admin/sign-in', ['email' => signInEmail($staffId), 'password' => $password]);
}

function signInFully(AdminBrowser $browser, string $staffId, bool $trust = false): void
{
    signInPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode(), 'trust_browser' => $trust])->assertRedirect('/admin');
}

function signInCodes(): int
{
    return count(RecordingSecurityMessages::installed()->codes);
}

describe('signing in (spec §1.8, §4.4)', function () {
    it('asks the password, then an SMS code, then signs in with a new session id', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser('10.1.2.3');

        signInPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
        $before = $browser->cookie('touchwood_admin_session');

        expect(signInWho($browser))->toBeNull()
            ->and(RecordingSecurityMessages::installed()->codes[0]['phone'])->toBe(DB::table('access.staff_users')->where('id', $staffId)->value('phone'))
            ->and(RecordingSecurityMessages::installed()->codes[0]['kind'])->toBe('sign_in');

        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        expect(signInWho($browser))->toBe($staffId)
            ->and($browser->cookie('touchwood_admin_session'))->not->toBeNull()
            ->and($browser->cookie('touchwood_admin_trust'))->toBeNull()
            ->and(DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->exists())->toBeFalse();
        expect($browser->cookie('touchwood_admin_session'))->not->toBe($before);

        $entry = DB::table('platform.audit_entries')->where('action', 'access.staff_user.signed_in')->first();

        expect($entry?->actor_type)->toBe('STAFF')
            ->and($entry?->actor_id)->toBe($staffId)
            ->and($entry?->ip_address)->toBe('10.1.2.3');
    });

    it('keeps the admin session in its own cookie, sent only to /admin, and gives the rest of the site its own back', function () {
        Route::middleware('web')->get('/_storefront_probe', fn () => 'ok');
        $browser = new AdminBrowser;
        $admin = signInPassword($browser, Fx::staff());
        $storefront = $browser->get('/_storefront_probe');

        $adminCookie = collect($admin->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'touchwood_admin_session');
        $storeCookies = collect($storefront->headers->getCookies())->map(fn ($cookie) => $cookie->getName());

        expect($adminCookie?->getPath())->toBe('/admin')
            ->and($adminCookie?->isHttpOnly())->toBeTrue()
            ->and($storeCookies)->toContain(config('session.cookie'))
            ->and($storeCookies)->not->toContain('touchwood_admin_session');
    });

    it('answers a wrong email or password the same way', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        $wrongPassword = signInPassword($browser, $staffId, 'not the password at all');
        $unknownEmail = $browser->post('/admin/sign-in', ['email' => 'nobody@example.test', 'password' => SIGN_IN_PASSWORD]);

        expect(AdminBrowser::formError($wrongPassword))->toBe((string) __('access::errors.invalid_credentials.detail'))
            ->and(AdminBrowser::formError($unknownEmail))->toBe((string) __('access::errors.invalid_credentials.detail'))
            ->and(signInCodes())->toBe(0);
    });

    it('locks an account for 15 minutes after 5 wrong passwords, even for the right one', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        foreach (range(1, 5) as $try) {
            signInPassword($browser, $staffId, 'wrong password '.$try);
        }

        expect(AdminBrowser::formError(signInPassword($browser, $staffId)))->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]))
            ->and(signInCodes())->toBe(0)
            ->and(Fx::audits('access.staff_user.locked_out', $staffId))->toBe(1);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(16));

        signInPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    });

    it('starts the count again after the right password', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        foreach (range(1, 4) as $try) {
            signInPassword($browser, $staffId, 'wrong password '.$try);
        }

        signInPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        foreach (range(1, 4) as $try) {
            signInPassword($browser, $staffId, 'wrong again '.$try);
        }

        signInPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    });

    it('makes an address that tries 10 wrong passwords wait, whatever the accounts', function () {
        $staffId = Fx::staff();
        $attacker = new AdminBrowser('10.0.0.9');

        foreach (range(1, 10) as $try) {
            $attacker->post('/admin/sign-in', ['email' => "someone{$try}@example.test", 'password' => 'guess']);
        }

        $audit = DB::table('platform.audit_entries')->where('action', 'access.staff_sign_in.address_locked')->get();

        // Audited once (owner, 2026-09-19); the 10 wrong passwords only counted. A guest's address
        // is never kept (Platform spec §1.5): a keyed fingerprint names it, not a plain hash that
        // could be reversed by trying every address.
        expect(AdminBrowser::formError(signInPassword($attacker, $staffId)))->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]))
            ->and($audit)->toHaveCount(1)
            ->and($audit->first()?->ip_address)->toBeNull()
            ->and($audit->first()?->subject_id)->toBe(app(Codes::class)->hash('sign-in-address', '10.0.0.9'))
            ->and($audit->first()?->subject_id)->not->toBe(hash('sha256', '10.0.0.9'))
            ->and(DB::table('platform.audit_entries')->where('action', 'like', 'access.staff%')->count())->toBe(1);

        signInPassword(new AdminBrowser('10.0.0.10'), $staffId)->assertRedirect('/admin/sign-in/code');
    });

    it('makes an address wait 15 minutes from its last wrong password, not its first', function () {
        $staffId = Fx::staff();
        $attacker = new AdminBrowser('10.0.0.50');
        $attacker->post('/admin/sign-in', ['email' => 'someone0@example.test', 'password' => 'guess']);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));

        foreach (range(1, 9) as $try) {
            $attacker->post('/admin/sign-in', ['email' => "someone{$try}@example.test", 'password' => 'guess']);
        }

        // 16 minutes after the first wrong password, 6 after the last.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));

        expect(AdminBrowser::formError(signInPassword($attacker, $staffId)))->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 9]));
    });

    it('counts an email typed in any case as the same account', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        $email = signInEmail($staffId);

        foreach ([strtoupper($email), ucfirst($email), $email, strtoupper($email), $email] as $typed) {
            $browser->post('/admin/sign-in', ['email' => $typed, 'password' => 'a wrong password']);
        }

        expect(AdminBrowser::formError(signInPassword($browser, $staffId)))->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]));
    });

    it('counts wrong passwords for an email with no account, so a lock tells a stranger nothing', function () {
        $browser = new AdminBrowser;

        foreach (range(1, 5) as $try) {
            $browser->post('/admin/sign-in', ['email' => 'nobody@example.test', 'password' => 'guess '.$try]);
        }

        expect(AdminBrowser::formError($browser->post('/admin/sign-in', ['email' => 'nobody@example.test', 'password' => 'guess'])))
            ->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]));
    });

    it('keeps counting an address after a right password: it gets back only that attempt', function () {
        $staffId = Fx::staff();
        $office = new AdminBrowser('10.0.0.30');

        foreach (range(1, 9) as $try) {
            $office->post('/admin/sign-in', ['email' => "someone{$try}@example.test", 'password' => 'guess']);
        }

        signInPassword($office, $staffId)->assertRedirect('/admin/sign-in/code');
        $office->post('/admin/sign-in', ['email' => 'someone10@example.test', 'password' => 'guess']);

        expect(AdminBrowser::formError(signInPassword($office, $staffId)))->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]));
    });

    it('refuses a staff member with no phone who is not a Super Admin: the password alone never picks a number', function () {
        $staffId = Fx::staff();
        DB::table('access.staff_users')->where('id', $staffId)->update(['phone' => null, 'phone_verified_at' => null]);

        expect(AdminBrowser::formError(signInPassword(new AdminBrowser, $staffId)))->toBe((string) __('access::errors.sign_in_refused.detail'))
            ->and(signInCodes())->toBe(0);
    });

    it('refuses the new-number step to someone whose code went to their own phone', function () {
        $staffId = Fx::staff();
        $phone = DB::table('access.staff_users')->where('id', $staffId)->value('phone');
        $browser = new AdminBrowser;
        signInPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        expect(AdminBrowser::formError($browser->post('/admin/sign-in/phone', ['phone' => '+966505551234'])))->toBe((string) __('access::errors.invalid_code.detail'))
            ->and(signInCodes())->toBe(1)
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('phone'))->toBe($phone);
    });

    it('counts wrong codes: after 5, even the right one is refused', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInPassword($browser, $staffId);
        $code = RecordingSecurityMessages::installed()->lastCode();

        foreach (range(1, 5) as $try) {
            $browser->post('/admin/sign-in/code', ['code' => $code === '000000' ? '111111' : '000000']);
        }

        expect(AdminBrowser::formError($browser->post('/admin/sign-in/code', ['code' => $code])))->not->toBeNull()
            ->and(signInWho($browser))->toBeNull();
    });

    it('gives the code step 15 minutes after the password', function (int $minutes, bool $signedIn) {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInPassword($browser, $staffId);

        // A fresh code, so only the 15 minutes can refuse it.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(14));
        $browser->post('/admin/sign-in/code/resend')->assertRedirect();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes($minutes - 14));
        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()]);

        expect(signInWho($browser))->toBe($signedIn ? $staffId : null);
    })->with([
        'at 14 minutes' => [14, true],
        'at 16 minutes' => [16, false],
    ]);

    it('refuses a disabled account after the right password, and ends its open session at once', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $browser = new AdminBrowser;
        signInFully($browser, $staffId);

        app(DisableStaffHandler::class)->handle(new DisableStaff($staffId));

        expect(signInWho($browser))->toBeNull()
            ->and(AdminBrowser::formError(signInPassword($browser, $staffId)))->toBe((string) __('access::errors.sign_in_refused.detail'));
    });

    it('reads only the session and cache tables on a warm request', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInFully($browser, $staffId);
        signInWho($browser);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $who = signInWho($browser);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        expect($who)->toBe($staffId)
            ->and($queries)->not->toBeEmpty();

        // Its own session table (owner, 2026-09-20) and the cache: no staff, role or store table.
        foreach ($queries as $query) {
            expect(str_contains($query, '"admin_sessions"') || str_contains($query, '"cache"'))->toBeTrue($query)
                ->and($query)->not->toContain('staff_users')
                ->and($query)->not->toContain('role')
                ->and($query)->not->toContain('"platform"');
        }
    });

    it('never lets a web request act as the system: with no session it is a guest', function () {
        expect((new AdminBrowser)->get('/admin/_who')->json('type'))->toBe('GUEST');
    });
});

describe('trusted browsers', function () {
    it('asks no code on a trusted browser for 30 days, and signing out keeps the trust', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInFully($browser, $staffId, trust: true);

        expect($browser->cookie('touchwood_admin_trust'))->not->toBeNull()
            ->and(Fx::audits('access.staff_user.browser_trusted', $staffId))->toBe(1);

        $browser->post('/admin/sign-out')->assertRedirect('/admin/sign-in');
        expect(signInWho($browser))->toBeNull()
            ->and(Fx::audits('access.staff_user.signed_out', $staffId))->toBe(1);

        $codes = signInCodes();
        signInPassword($browser, $staffId)->assertRedirect('/admin');

        $trustedEntry = DB::table('platform.audit_entries')->where('action', 'access.staff_user.signed_in')->orderByDesc('occurred_at')->orderByDesc('id')->value('changes');

        expect(signInWho($browser))->toBe($staffId)
            ->and(signInCodes())->toBe($codes)
            ->and(Fx::audits('access.staff_user.signed_in', $staffId))->toBe(2)
            ->and(json_decode((string) $trustedEntry, true))->toBe(['trusted_browser' => [null, true]]);

        $browser->post('/admin/sign-out');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(29));
        signInPassword($browser, $staffId)->assertRedirect('/admin');

        $browser->post('/admin/sign-out');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(2));
        signInPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    });

    it('keeps the trust in a cookie sent only to /admin, out of scripts\' reach, for 30 days', function () {
        $browser = new AdminBrowser;
        signInPassword($browser, Fx::staff());
        $response = $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode(), 'trust_browser' => true]);

        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'touchwood_admin_trust');

        expect($cookie?->getPath())->toBe('/admin')
            ->and($cookie?->isHttpOnly())->toBeTrue()
            ->and($cookie?->getMaxAge())->toBeGreaterThan(29 * 86400)->toBeLessThanOrEqual(30 * 86400);
    });

    it('trusts only the browser the cookie came from, for its own staff member', function () {
        $first = Fx::staff();
        $browser = new AdminBrowser;
        signInFully($browser, $first, trust: true);
        $browser->post('/admin/sign-out');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        signInPassword($browser, Fx::staff())->assertRedirect('/admin/sign-in/code');
    });

    it('forgets every trusted browser when the account changes this way (spec §1.8)', function (bool $superAdmin, Closure $change) {
        $staffId = Fx::staff(superAdmin: $superAdmin);
        signInFully(new AdminBrowser, $staffId, trust: true);
        $other = new AdminBrowser('10.0.0.20');
        signInFully($other, $staffId, trust: true);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        expect(DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->count())->toBe(2);

        $change($staffId);

        expect(DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->exists())->toBeFalse();
    })->with([
        'disabled' => [false, fn (string $id) => app(DisableStaffHandler::class)->handle(new DisableStaff($id))],
        'an admin gave them a new phone' => [false, fn (string $id) => app(UpdateStaffProfileHandler::class)->handle(
            new UpdateStaffProfile($id, 'Staff', 'Tester', 'Tester', '1990-01-01', 'SA', null, '+966505550001', null),
        )],
        'they changed their own phone' => [false, function (string $id): void {
            Fx::actAsStaff($id);
            app(RequestOwnPhoneChangeHandler::class)->handle(new RequestOwnPhoneChange('+966505550002', SIGN_IN_PASSWORD, '10.0.0.1'));
            app(VerifyOwnPhoneChangeHandler::class)->handle(new VerifyOwnPhoneChange(RecordingSecurityMessages::installed()->lastCode()));
        }],
        'a Super Admin revoked' => [true, function (string $id): void {
            Fx::staff(superAdmin: true);
            app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin(signInEmail($id)));
        }],
        'a Super Admin whose phone was reset' => [true, fn (string $id) => app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone(signInEmail($id)))],
    ]);
});

describe('sessions end (spec §1.8)', function () {
    it('after 30 minutes idle', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInFully($browser, $staffId);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(29));
        expect(signInWho($browser))->toBe($staffId);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));
        expect(signInWho($browser))->toBeNull();
    });

    it('12 hours after signing in, however busy', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInFully($browser, $staffId);

        foreach (range(1, 35) as $step) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(20));
            expect(signInWho($browser))->toBe($staffId);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(21));
        expect(signInWho($browser))->toBeNull();
    });

    it('everywhere else when the password changes; this one stays', function () {
        $staffId = Fx::staff();
        $here = new AdminBrowser;
        $there = new AdminBrowser;
        signInFully($here, $staffId, trust: true);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        signInFully($there, $staffId);

        $here->post('/admin/account/password', ['current_password' => SIGN_IN_PASSWORD, 'password' => 'a brand new long password'])->assertRedirect();

        expect(signInWho($here))->toBe($staffId)
            ->and(signInWho($there))->toBeNull()
            ->and(DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(Fx::audits('access.staff_user.password_changed', $staffId))->toBe(1);
    });

    it('for good when the account is disabled, even once it is enabled again', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $browser = new AdminBrowser;
        signInFully($browser, $staffId);

        // Disabled and enabled while this browser sends nothing.
        app(DisableStaffHandler::class)->handle(new DisableStaff($staffId));
        app(EnableStaffHandler::class)->handle(new EnableStaff($staffId));

        expect(DB::table('access.staff_users')->where('id', $staffId)->value('status'))->toBe('ACTIVE')
            ->and(signInWho($browser))->toBeNull();
    });

    it('half-way too: a sign-in waiting for its code ends when the password is reset', function () {
        $staffId = Fx::staff();
        $waiting = new AdminBrowser;
        signInPassword($waiting, $staffId)->assertRedirect('/admin/sign-in/code');
        $code = RecordingSecurityMessages::installed()->lastCode();

        $other = new AdminBrowser;
        $other->post('/admin/password/forgot', ['email' => signInEmail($staffId)]);
        $other->post('/admin/password/reset/'.RecordingSecurityMessages::installed()->lastPasswordResetToken(), ['password' => 'a brand new long password'])->assertRedirect('/admin/sign-in');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $codes = signInCodes();

        expect(AdminBrowser::formError($waiting->post('/admin/sign-in/code/resend')))->toBe((string) __('access::errors.invalid_code.detail'))
            ->and(signInCodes())->toBe($codes)
            ->and(AdminBrowser::formError($waiting->post('/admin/sign-in/code', ['code' => $code])))->toBe((string) __('access::errors.invalid_code.detail'))
            ->and(signInWho($waiting))->toBeNull();
    });

    it('everywhere after a reset by email link, which also forgets every trusted browser', function () {
        $staffId = Fx::staff();
        $trusted = new AdminBrowser;
        signInFully($trusted, $staffId, trust: true);
        // Read once, so the session version is in the cache: the reset must replace it there.
        expect(signInWho($trusted))->toBe($staffId);

        $other = new AdminBrowser;
        $other->post('/admin/password/forgot', ['email' => signInEmail($staffId)])->assertRedirect();
        $token = RecordingSecurityMessages::installed()->lastPasswordResetToken();
        $other->post("/admin/password/reset/{$token}", ['password' => 'a brand new long password'])->assertRedirect('/admin/sign-in');

        expect(signInWho($trusted))->toBeNull()
            ->and(Fx::audits('access.staff_user.password_reset', $staffId))->toBe(1);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $trusted->post('/admin/sign-in', ['email' => signInEmail($staffId), 'password' => 'a brand new long password'])->assertRedirect('/admin/sign-in/code');
    });
});

describe('limits on the way in', function () {
    it('sends at most 3 reset emails an hour to one account, and says the same every time', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        foreach (range(1, 4) as $request) {
            $browser->post('/admin/password/forgot', ['email' => signInEmail($staffId)])->assertRedirect();
        }

        expect(RecordingSecurityMessages::installed()->passwordResets)->toHaveCount(3);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(61));
        $browser->post('/admin/password/forgot', ['email' => signInEmail($staffId)]);

        expect(RecordingSecurityMessages::installed()->passwordResets)->toHaveCount(4);
    });

    it('resends a sign-in code no sooner than a minute later', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInPassword($browser, $staffId);

        expect(AdminBrowser::formError($browser->post('/admin/sign-in/code/resend')))->not->toBeNull()
            ->and(signInCodes())->toBe(1);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $browser->post('/admin/sign-in/code/resend')->assertRedirect();

        expect(signInCodes())->toBe(2);
        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');
    });

    it('changes the password only with the current one, keeping sessions as they are otherwise', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInFully($browser, $staffId);

        $refused = $browser->post('/admin/account/password', ['current_password' => 'not my password', 'password' => 'a brand new long password']);

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(signInWho($browser))->toBe($staffId)
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('session_version'))->toBe(0);
    });

    it('audits an address made to wait by wrong current passwords too', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser('10.0.0.60');
        signInFully($browser, $staffId);

        foreach (range(1, 9) as $try) {
            (new AdminBrowser('10.0.0.60'))->post('/admin/sign-in', ['email' => "someone{$try}@example.test", 'password' => 'guess']);
        }

        $browser->post('/admin/account/password', ['current_password' => 'a guess', 'password' => 'a brand new long password']);

        expect(DB::table('platform.audit_entries')->where('action', 'access.staff_sign_in.address_locked')->value('subject_id'))
            ->toBe(app(Codes::class)->hash('sign-in-address', '10.0.0.60'));
    });

    it('counts a wrong current password like a wrong password at sign-in', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInFully($browser, $staffId);

        foreach (range(1, 5) as $try) {
            $browser->post('/admin/account/password', ['current_password' => 'a guess '.$try, 'password' => 'a brand new long password']);
        }

        $right = $browser->post('/admin/account/password', ['current_password' => SIGN_IN_PASSWORD, 'password' => 'a brand new long password']);

        expect(AdminBrowser::formError($right))->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]))
            ->and(Fx::audits('access.staff_user.locked_out', $staffId))->toBe(1)
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('session_version'))->toBe(0);
    });

    it('asks a new password to follow the rules: 12 characters, not leaked', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInFully($browser, $staffId);

        $short = $browser->post('/admin/account/password', ['current_password' => SIGN_IN_PASSWORD, 'password' => 'too short']);
        $leaked = $browser->post('/admin/account/password', ['current_password' => SIGN_IN_PASSWORD, 'password' => FakeBreachList::LEAKED]);

        // Both reasons are answered with the one message, which never says which of the two it
        // was: a form that said "this password has leaked" would confirm the password to a
        // stranger at the keyboard (review of step 7).
        $refused = (string) __('access::errors.password_too_weak.detail', ['min' => 12]);

        expect(AdminBrowser::formError($short))->toBe($refused)
            ->and(AdminBrowser::formError($leaked))->toBe($refused)
            ->and(signInWho($browser))->toBe($staffId);
    });
});

describe('password reset', function () {
    it('answers the same whether or not the email has an account, and sends only to an active one', function () {
        $browser = new AdminBrowser;
        $known = $browser->post('/admin/password/forgot', ['email' => signInEmail(Fx::staff())]);
        $unknown = $browser->post('/admin/password/forgot', ['email' => 'nobody@example.test']);
        $disabled = $browser->post('/admin/password/forgot', ['email' => signInEmail(Fx::staff(StaffStatus::Disabled))]);

        expect(AdminBrowser::flashed($known, 'status'))->toBe(AdminBrowser::flashed($unknown, 'status'))
            ->and(AdminBrowser::flashed($disabled, 'status'))->toBe(AdminBrowser::flashed($known, 'status'))
            ->and(RecordingSecurityMessages::installed()->passwordResets)->toHaveCount(1);
    });

    it('works 30 minutes', function (int $minutes, bool $works) {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        $browser->post('/admin/password/forgot', ['email' => signInEmail($staffId)]);
        $token = RecordingSecurityMessages::installed()->lastPasswordResetToken();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes($minutes));
        $response = $browser->post("/admin/password/reset/{$token}", ['password' => 'a brand new long password']);

        expect(AdminBrowser::formError($response))->toBe($works ? null : (string) __('access::errors.invalid_or_expired_link.detail'));
    })->with([
        'at 29 minutes' => [29, true],
        'at 31 minutes' => [31, false],
    ]);

    it('works once', function () {
        $browser = new AdminBrowser;
        $browser->post('/admin/password/forgot', ['email' => signInEmail(Fx::staff())]);
        $token = RecordingSecurityMessages::installed()->lastPasswordResetToken();

        $browser->post("/admin/password/reset/{$token}", ['password' => 'a brand new long password'])->assertRedirect('/admin/sign-in');

        expect(AdminBrowser::formError($browser->post("/admin/password/reset/{$token}", ['password' => 'another new long password'])))
            ->toBe((string) __('access::errors.invalid_or_expired_link.detail'));
    });

    it('dies when the account is disabled or its email changes (review of step 3b)', function (bool $superAdmin, Closure $change) {
        $staffId = $superAdmin ? Fx::staff(superAdmin: true) : Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $browser = new AdminBrowser;
        $browser->post('/admin/password/forgot', ['email' => signInEmail($staffId)]);
        $token = RecordingSecurityMessages::installed()->lastPasswordResetToken();

        $change($staffId);

        expect(AdminBrowser::formError($browser->post("/admin/password/reset/{$token}", ['password' => 'a brand new long password'])))
            ->toBe((string) __('access::errors.invalid_or_expired_link.detail'));
    })->with([
        'disabled, then enabled again' => [false, function (string $id): void {
            app(DisableStaffHandler::class)->handle(new DisableStaff($id));
            app(EnableStaffHandler::class)->handle(new EnableStaff($id));
        }],
        // Revoking closes the account and frees its email at once (amendment 45), so the link dies
        // with it: the way back is a fresh invitation.
        'a Super Admin revoked' => [true, function (string $id): void {
            Fx::staff(superAdmin: true);
            app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin(signInEmail($id)));
        }],
        'a new email' => [false, function (string $id): void {
            app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($id, 'moved@example.test'));
            (new AdminBrowser)->post('/admin/email-change/'.RecordingSecurityMessages::installed()->lastEmailChangeToken())->assertRedirect('/admin/sign-in');

            expect(signInEmail($id))->toBe('moved@example.test');
        }],
    ]);

    it('sends the email only after the answer, so its timing tells nothing (review of step 3b)', function () {
        $staffId = Fx::staff();

        app(RequestStaffPasswordResetHandler::class)->handle(new RequestStaffPasswordReset(signInEmail($staffId)));

        expect(RecordingSecurityMessages::installed()->passwordResets)->toBe([]);

        defer()->invoke();

        expect(RecordingSecurityMessages::installed()->passwordResets)->toHaveCount(1);
    });

    it('links to APP_URL, whatever host the request names', function () {
        config(['app.url' => 'https://panel.touchwood.test']);
        $browser = new AdminBrowser('127.0.0.1', ['HTTP_HOST' => 'attacker.example']);

        $browser->post('/admin/password/forgot', ['email' => signInEmail(Fx::staff())]);

        expect(RecordingSecurityMessages::installed()->passwordResets[0]['link'] ?? null)->toStartWith('https://panel.touchwood.test/admin/password/reset/');
    });
});

describe('email links while signed in (amendment 31)', function () {
    it('signs the admin session out first, then accepts the invitation; the new staff member then signs in as always', function () {
        $adminId = Fx::staff();
        $browser = new AdminBrowser;
        signInFully($browser, $adminId);

        app(InviteStaffHandler::class)->handle(new InviteStaff(
            'noura@example.test', 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966501111111', 'en',
            AccessLevel::SelectedStores, [Fx::storeId('sa')], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]),
        ));
        $token = RecordingSecurityMessages::installed()->lastInvitationToken();
        $invitee = (string) DB::table('access.staff_users')->where('email', 'noura@example.test')->value('id');

        $browser->post("/admin/invitation/{$token}", ['password' => SIGN_IN_PASSWORD, 'phone' => '+966501111111'])->assertRedirect("/admin/invitation/{$token}/code");

        expect(signInWho($browser))->toBeNull()
            ->and(Fx::audits('access.staff_user.signed_out', $adminId))->toBe(1)
            ->and(collect(RecordingSecurityMessages::installed()->codes)->last()['kind'] ?? null)->toBe('verify');

        $accepted = $browser->post("/admin/invitation/{$token}/code", ['code' => RecordingSecurityMessages::installed()->lastCode()]);

        // Staff are not signed in by accepting (owner, 2026-09-19): the sign-in page, password and code.
        $accepted->assertRedirect('/admin/sign-in');
        expect(AdminBrowser::flashed($accepted, 'status'))->toBe((string) __('access::auth.invitation_accepted'))
            ->and(signInWho($browser))->toBeNull()
            ->and(Fx::audits('access.staff_user.signed_in', $invitee))->toBe(0)
            ->and(DB::table('access.staff_users')->where('id', $invitee)->value('status'))->toBe('ACTIVE');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        signInFully($browser, $invitee);

        expect(signInWho($browser))->toBe($invitee);
    });

    it('signs the admin out at the code step too, when they signed in again in between', function () {
        app(InviteStaffHandler::class)->handle(new InviteStaff(
            'noura@example.test', 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966501111111', 'en',
            AccessLevel::SelectedStores, [Fx::storeId('sa')], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]),
        ));
        $token = RecordingSecurityMessages::installed()->lastInvitationToken();
        $invitee = (string) DB::table('access.staff_users')->where('email', 'noura@example.test')->value('id');
        $browser = new AdminBrowser;
        $browser->post("/admin/invitation/{$token}", ['password' => SIGN_IN_PASSWORD, 'phone' => '+966501111111']);
        $code = RecordingSecurityMessages::installed()->lastCode();

        $adminId = Fx::staff();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        signInFully($browser, $adminId);

        $browser->post("/admin/invitation/{$token}/code", ['code' => $code])->assertRedirect('/admin/sign-in');

        expect(signInWho($browser))->toBeNull()
            ->and(Fx::audits('access.staff_user.signed_out', $adminId))->toBe(1)
            ->and(DB::table('access.staff_users')->where('id', $invitee)->value('status'))->toBe('ACTIVE');
    });

    it('signs the session out first, then confirms an email change', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        $browser = new AdminBrowser;
        signInFully($browser, $superAdmin);
        DB::table('access.staff_email_changes')->insert([
            'staff_user_id' => $superAdmin, 'new_email' => 'owner.new@example.test', 'token_hash' => hash('sha256', 'the-link'),
            'expires_at' => now()->addDay(), 'requested_by' => $superAdmin, 'created_at' => now(),
        ]);

        $browser->post('/admin/email-change/the-link')->assertRedirect('/admin/sign-in');

        expect(signInEmail($superAdmin))->toBe('owner.new@example.test')
            ->and(signInWho($browser))->toBeNull();
    });
});

describe('phones at sign-in', function () {
    it('asks a Super Admin whose phone was reset for a new number, and verifies it with the code (amendment 14)', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone(signInEmail($superAdmin)));
        $browser = new AdminBrowser;

        signInPassword($browser, $superAdmin)->assertRedirect('/admin/sign-in/phone');
        $browser->post('/admin/sign-in/phone', ['phone' => '+966 50 777 7777'])->assertRedirect('/admin/sign-in/code');
        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        $row = DB::table('access.staff_users')->where('id', $superAdmin)->first();

        expect(signInWho($browser))->toBe($superAdmin)
            ->and($row?->phone)->toBe('+966507777777')
            ->and($row?->phone_verified_at)->not->toBeNull()
            ->and(Fx::audits('access.staff_user.phone_verified', $superAdmin))->toBe(1);
    });

    it('lets only one browser give the Super Admin a new number', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone(signInEmail($superAdmin)));
        $first = new AdminBrowser;
        $second = new AdminBrowser('10.0.0.40');
        signInPassword($first, $superAdmin)->assertRedirect('/admin/sign-in/phone');
        signInPassword($second, $superAdmin)->assertRedirect('/admin/sign-in/phone');

        $second->post('/admin/sign-in/phone', ['phone' => '+966507777777']);
        $second->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        expect(AdminBrowser::formError($first->post('/admin/sign-in/phone', ['phone' => '+966506666666'])))->toBe((string) __('access::errors.invalid_code.detail'))
            ->and(DB::table('access.staff_users')->where('id', $superAdmin)->value('phone'))->toBe('+966507777777');
    });

    it('forgets a code on its way to a number that has since changed (review of step 3b)', function (Closure $change) {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        signInPassword($browser, $staffId);
        $oldCode = RecordingSecurityMessages::installed()->lastCode();

        $change($staffId);

        expect(DB::table('access.staff_sign_in_codes')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(AdminBrowser::formError($browser->post('/admin/sign-in/code', ['code' => $oldCode])))->toBe((string) __('access::errors.invalid_code.detail'))
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('phone'))->toBe('+966505550009');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $browser->post('/admin/sign-in/code/resend')->assertRedirect();

        expect(RecordingSecurityMessages::installed()->lastCode())->not->toBe($oldCode)
            ->and(collect(RecordingSecurityMessages::installed()->codes)->last()['phone'] ?? null)->toBe('+966505550009');
    })->with([
        'by an admin' => fn (string $id) => app(UpdateStaffProfileHandler::class)->handle(
            new UpdateStaffProfile($id, 'Staff', 'Tester', 'Tester', '1990-01-01', 'SA', null, '+966505550009', null),
        ),
        'by themselves, elsewhere' => function (string $id): void {
            Fx::asSystem(function () use ($id): void {
                Fx::actAsStaff($id);
                app(RequestOwnPhoneChangeHandler::class)->handle(new RequestOwnPhoneChange('+966505550009', SIGN_IN_PASSWORD, '10.0.0.1'));
                app(VerifyOwnPhoneChangeHandler::class)->handle(new VerifyOwnPhoneChange(RecordingSecurityMessages::installed()->lastCode()));
            });
        },
    ]);

    it('never lets a code bring back a number the account no longer has', function (?string $now) {
        $staffId = Fx::staff();
        $old = DB::table('access.staff_users')->where('id', $staffId)->value('phone');
        $browser = new AdminBrowser;
        signInPassword($browser, $staffId);

        // Changed behind the handlers' back, so only the code step's own checks stand in the way.
        DB::table('access.staff_users')->where('id', $staffId)->update(['phone' => $now, 'phone_verified_at' => null]);
        $code = RecordingSecurityMessages::installed()->lastCode();

        // A resend goes to the number the account has now, never to the one the first code went to.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $resend = $browser->post('/admin/sign-in/code/resend');
        $lastPhone = collect(RecordingSecurityMessages::installed()->codes)->last()['phone'] ?? null;

        expect($now === null ? AdminBrowser::formError($resend) : $lastPhone)->toBe($now ?? (string) __('access::errors.invalid_code.detail'))
            ->and(AdminBrowser::formError($browser->post('/admin/sign-in/code', ['code' => $code])))->toBe((string) __('access::errors.invalid_code.detail'))
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('phone'))->toBe($now)
            ->and($now)->not->toBe($old);
    })->with([
        'another number' => ['+966505550010'],
        'no number' => [null],
    ]);

    it('refuses in code a number taken while its code was on the way, before the unique index does', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone(signInEmail($superAdmin)));
        $browser = new AdminBrowser;
        signInPassword($browser, $superAdmin);
        $browser->post('/admin/sign-in/phone', ['phone' => '+966507777777'])->assertRedirect('/admin/sign-in/code');

        DB::table('access.staff_users')->where('id', Fx::staff())->update(['phone' => '+966507777777']);
        Fx::withoutStaffUniqueIndexes();

        expect(AdminBrowser::formError($browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])))
            ->toBe((string) __('access::errors.phone_already_in_use.detail'))
            ->and(DB::table('access.staff_users')->where('id', $superAdmin)->value('phone'))->toBeNull();
    });

    it('sends the code to a number an admin entered, and the right code verifies it', function () {
        $staffId = Fx::staff();
        DB::table('access.staff_users')->where('id', $staffId)->update(['phone' => '+966508888888', 'phone_verified_at' => null]);
        $browser = new AdminBrowser;

        signInFully($browser, $staffId);

        expect(RecordingSecurityMessages::installed()->codes[0]['phone'])->toBe('+966508888888')
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('phone_verified_at'))->not->toBeNull();
    });

    it('refuses a new number another staff member uses', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone(signInEmail($superAdmin)));
        $taken = (string) DB::table('access.staff_users')->where('id', Fx::staff())->value('phone');
        $browser = new AdminBrowser;
        signInPassword($browser, $superAdmin);

        expect(AdminBrowser::formError($browser->post('/admin/sign-in/phone', ['phone' => $taken])))
            ->toBe((string) __('access::errors.phone_already_in_use.detail'));
    });
});

describe('what needs a session', function () {
    it('sends someone not signed in to the sign-in page', function () {
        (new AdminBrowser)->post('/admin/sign-out')->assertRedirect('/admin/sign-in');
    });

    it('refuses the code step without the password step', function () {
        expect(AdminBrowser::formError((new AdminBrowser)->post('/admin/sign-in/code', ['code' => '123456'])))->not->toBeNull();
    });

    it('lets a new Super Admin in once they accept, with no other step', function () {
        app(CreateSuperAdminHandler::class)->handle(new CreateSuperAdmin('owner@example.test', 'Abood', 'Owner', 'Founder', '1995-01-01', 'SA', '+966501112233', 'ar'));
        $token = RecordingSecurityMessages::installed()->lastInvitationToken();
        $browser = new AdminBrowser;

        $browser->post("/admin/invitation/{$token}", ['password' => SIGN_IN_PASSWORD, 'phone' => '+966501112233']);
        $browser->post("/admin/invitation/{$token}/code", ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');
        $superAdmin = (string) DB::table('access.staff_users')->where('email', 'owner@example.test')->value('id');

        expect(signInWho($browser))->toBe($superAdmin)
            ->and(Fx::audits('access.staff_user.signed_in', $superAdmin))->toBe(1);
    });
});

it('takes out every line where PostgreSQL repeats a value, keeping what went wrong', function () {
    $message = "SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: \"secret one\"\n"
        ."DETAIL:  Failing row contains (secret two).\nHINT:  Check the value.\n"
        ."CONTEXT:  unnamed portal parameter \$1 = 'secret three' (Connection: pgsql, SQL: update \"t\" set \"n\" = ?)";

    $logged = QueryErrorLog::withoutValues($message);

    expect($logged)->not->toContain('secret')
        ->and($logged)->toContain('invalid input syntax for type integer')
        ->and($logged)->toContain('HINT:  Check the value.')
        ->and($logged)->toContain('(Connection: pgsql');
});

it('logs a failed query without its values (amendment 33)', function (Closure $fail) {
    $log = Log::spy();

    try {
        $fail();
    } catch (QueryException $e) {
        report($e);
        // The message keeps what went wrong, never the values; the context holds only the class and code.
        $log->shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => ! str_contains($message, 'secret.person@example.test')
            && ! str_contains($message, '+966509990000') && str_contains($message, 'SQLSTATE') && str_contains($message, 'staff_users')
            && array_keys($context) === ['exception', 'code']);

        return;
    }

    throw new LogicException('The query should have failed.');
})->with([
    'a row the database refuses' => fn () => DB::table('access.staff_users')->insert(['id' => strtolower((string) Str::ulid()), 'email' => 'secret.person@example.test', 'phone' => '+966509990000']),
    'a value already taken' => function (): void {
        DB::table('access.staff_users')->where('id', Fx::staff())->update(['email' => 'secret.person@example.test']);
        DB::table('access.staff_users')->where('id', Fx::staff())->update(['email' => 'secret.person@example.test', 'phone' => '+966509990000']);
    },
    'a value of the wrong type' => fn () => DB::table('access.staff_users')->where('id', Fx::staff())->update(['date_of_birth' => 'secret.person@example.test +966509990000']),
    'a number that is not one' => fn () => DB::table('access.staff_users')->where('id', Fx::staff())->update(['session_version' => 'secret.person@example.test +966509990000']),
]);
