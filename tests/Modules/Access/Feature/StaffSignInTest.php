<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdmin;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdminHandler;
use Modules\Access\Application\Command\DisableStaff\DisableStaff;
use Modules\Access\Application\Command\DisableStaff\DisableStaffHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChange;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChangeHandler;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhone;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhoneHandler;
use Modules\Access\Application\Command\RevokeSuperAdmin\RevokeSuperAdmin;
use Modules\Access\Application\Command\RevokeSuperAdmin\RevokeSuperAdminHandler;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfile;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfileHandler;
use Modules\Access\Application\Command\VerifyOwnPhoneChange\VerifyOwnPhoneChange;
use Modules\Access\Application\Command\VerifyOwnPhoneChange\VerifyOwnPhoneChangeHandler;
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

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

const ADMIN_PASSWORD = 'a long enough password';

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

function adminWho(AdminBrowser $browser): ?string
{
    $who = $browser->get('/admin/_who')->json();

    return is_array($who) && ($who['type'] ?? null) === 'STAFF' && is_string($who['id'] ?? null) ? $who['id'] : null;
}

function adminEmail(string $staffId): string
{
    return (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
}

/**
 * @return TestResponse<Response>
 */
function adminPassword(AdminBrowser $browser, string $staffId, string $password = ADMIN_PASSWORD): TestResponse
{
    return $browser->post('/admin/sign-in', ['email' => adminEmail($staffId), 'password' => $password]);
}

function adminSignIn(AdminBrowser $browser, string $staffId, bool $trust = false): void
{
    adminPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode(), 'trust_browser' => $trust])->assertRedirect('/admin');
}

function adminCodes(): int
{
    return count(RecordingSecurityMessages::installed()->codes);
}

describe('signing in (spec §1.8, §4.4)', function () {
    it('asks the password, then an SMS code, then signs in with a new session id', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        adminPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
        $before = $browser->cookie('touchwood_admin_session');

        expect(adminWho($browser))->toBeNull()
            ->and(RecordingSecurityMessages::installed()->codes[0]['phone'])->toBe(DB::table('access.staff_users')->where('id', $staffId)->value('phone'));

        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        expect(adminWho($browser))->toBe($staffId)
            ->and($browser->cookie('touchwood_admin_session'))->not->toBeNull()
            ->and($browser->cookie('touchwood_admin_trust'))->toBeNull()
            ->and(DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->exists())->toBeFalse();
        expect($browser->cookie('touchwood_admin_session'))->not->toBe($before);

        $entry = DB::table('platform.audit_entries')->where('action', 'access.staff_user.signed_in')->first();

        expect($entry?->actor_type)->toBe('STAFF')
            ->and($entry?->actor_id)->toBe($staffId)
            ->and($entry?->ip_address)->toBe('127.0.0.1');
    });

    it('keeps the admin session in its own cookie, sent only to /admin', function () {
        $browser = new AdminBrowser;
        $storefront = get('/');
        $admin = adminPassword($browser, Fx::staff());

        $adminCookie = collect($admin->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === 'touchwood_admin_session');
        $storeCookies = collect($storefront->headers->getCookies())->map(fn ($cookie) => $cookie->getName());

        expect($adminCookie?->getPath())->toBe('/admin')
            ->and($adminCookie?->isHttpOnly())->toBeTrue()
            ->and($storeCookies)->not->toContain('touchwood_admin_session');
    });

    it('answers a wrong email or password the same way', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        $wrongPassword = adminPassword($browser, $staffId, 'not the password at all');
        $unknownEmail = $browser->post('/admin/sign-in', ['email' => 'nobody@example.test', 'password' => ADMIN_PASSWORD]);

        expect(AdminBrowser::formError($wrongPassword))->toBe((string) __('access::errors.invalid_credentials.detail'))
            ->and(AdminBrowser::formError($unknownEmail))->toBe((string) __('access::errors.invalid_credentials.detail'))
            ->and(adminCodes())->toBe(0);
    });

    it('locks an account for 15 minutes after 5 wrong passwords, even for the right one', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        foreach (range(1, 5) as $try) {
            adminPassword($browser, $staffId, 'wrong password '.$try);
        }

        expect(AdminBrowser::formError(adminPassword($browser, $staffId)))->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]))
            ->and(adminCodes())->toBe(0)
            ->and(Fx::audits('access.staff_user.locked_out', $staffId))->toBe(1);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(16));

        adminPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    });

    it('starts the count again after the right password', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        foreach (range(1, 4) as $try) {
            adminPassword($browser, $staffId, 'wrong password '.$try);
        }

        adminPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        foreach (range(1, 4) as $try) {
            adminPassword($browser, $staffId, 'wrong again '.$try);
        }

        adminPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    });

    it('makes an address that tries 10 wrong passwords wait, whatever the accounts', function () {
        $staffId = Fx::staff();
        $attacker = new AdminBrowser('10.0.0.9');

        foreach (range(1, 10) as $try) {
            $attacker->post('/admin/sign-in', ['email' => "someone{$try}@example.test", 'password' => 'guess']);
        }

        expect(AdminBrowser::formError(adminPassword($attacker, $staffId)))->toBe((string) __('access::errors.account_locked.detail', ['minutes' => 15]));

        adminPassword(new AdminBrowser('10.0.0.10'), $staffId)->assertRedirect('/admin/sign-in/code');
    });

    it('refuses a disabled account after the right password, and ends its open session at once', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $browser = new AdminBrowser;
        adminSignIn($browser, $staffId);

        app(DisableStaffHandler::class)->handle(new DisableStaff($staffId));

        expect(adminWho($browser))->toBeNull()
            ->and(AdminBrowser::formError(adminPassword($browser, $staffId)))->toBe((string) __('access::errors.sign_in_refused.detail'));
    });

    it('reads only the session and cache tables on a warm request', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        adminSignIn($browser, $staffId);
        adminWho($browser);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $who = adminWho($browser);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        expect($who)->toBe($staffId)
            ->and($queries)->not->toBeEmpty();

        foreach ($queries as $query) {
            expect(str_contains($query, '"sessions"') || str_contains($query, '"cache"'))->toBeTrue($query)
                ->and($query)->not->toContain('"access"')
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
        adminSignIn($browser, $staffId, trust: true);

        expect($browser->cookie('touchwood_admin_trust'))->not->toBeNull()
            ->and(Fx::audits('access.staff_user.browser_trusted', $staffId))->toBe(1);

        $browser->post('/admin/sign-out')->assertRedirect('/admin/sign-in');
        expect(adminWho($browser))->toBeNull()
            ->and(Fx::audits('access.staff_user.signed_out', $staffId))->toBe(1);

        $codes = adminCodes();
        adminPassword($browser, $staffId)->assertRedirect('/admin');

        expect(adminWho($browser))->toBe($staffId)
            ->and(adminCodes())->toBe($codes);

        $browser->post('/admin/sign-out');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(31));

        adminPassword($browser, $staffId)->assertRedirect('/admin/sign-in/code');
    });

    it('trusts only the browser the cookie came from, for its own staff member', function () {
        $first = Fx::staff();
        $browser = new AdminBrowser;
        adminSignIn($browser, $first, trust: true);
        $browser->post('/admin/sign-out');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        adminPassword($browser, Fx::staff())->assertRedirect('/admin/sign-in/code');
    });

    it('forgets every trusted browser when the account changes this way (spec §1.8)', function (bool $superAdmin, Closure $change) {
        $staffId = Fx::staff(superAdmin: $superAdmin);
        adminSignIn(new AdminBrowser, $staffId, trust: true);
        $other = new AdminBrowser('10.0.0.20');
        adminSignIn($other, $staffId, trust: true);
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
            app(RequestOwnPhoneChangeHandler::class)->handle(new RequestOwnPhoneChange('+966505550002'));
            app(VerifyOwnPhoneChangeHandler::class)->handle(new VerifyOwnPhoneChange(RecordingSecurityMessages::installed()->lastCode()));
        }],
        'a Super Admin revoked' => [true, function (string $id): void {
            Fx::staff(superAdmin: true);
            app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin(adminEmail($id)));
        }],
        'a Super Admin whose phone was reset' => [true, fn (string $id) => app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone(adminEmail($id)))],
    ]);
});

describe('sessions end (spec §1.8)', function () {
    it('after 30 minutes idle', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        adminSignIn($browser, $staffId);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(29));
        expect(adminWho($browser))->toBe($staffId);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));
        expect(adminWho($browser))->toBeNull();
    });

    it('12 hours after signing in, however busy', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        adminSignIn($browser, $staffId);

        foreach (range(1, 35) as $step) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(20));
            expect(adminWho($browser))->toBe($staffId);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(21));
        expect(adminWho($browser))->toBeNull();
    });

    it('everywhere else when the password changes; this one stays', function () {
        $staffId = Fx::staff();
        $here = new AdminBrowser;
        $there = new AdminBrowser;
        adminSignIn($here, $staffId, trust: true);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        adminSignIn($there, $staffId);

        $here->post('/admin/account/password', ['current_password' => ADMIN_PASSWORD, 'password' => 'a brand new long password'])->assertRedirect();

        expect(adminWho($here))->toBe($staffId)
            ->and(adminWho($there))->toBeNull()
            ->and(DB::table('access.staff_trusted_browsers')->where('staff_user_id', $staffId)->exists())->toBeFalse();
    });

    it('everywhere after a reset by email link, which also forgets every trusted browser', function () {
        $staffId = Fx::staff();
        $trusted = new AdminBrowser;
        adminSignIn($trusted, $staffId, trust: true);
        // Read once, so the session version is in the cache: the reset must replace it there.
        expect(adminWho($trusted))->toBe($staffId);

        $other = new AdminBrowser;
        $other->post('/admin/password/forgot', ['email' => adminEmail($staffId)])->assertRedirect();
        $token = RecordingSecurityMessages::installed()->lastPasswordResetToken();
        $other->post("/admin/password/reset/{$token}", ['password' => 'a brand new long password'])->assertRedirect('/admin/sign-in');

        expect(adminWho($trusted))->toBeNull();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $trusted->post('/admin/sign-in', ['email' => adminEmail($staffId), 'password' => 'a brand new long password'])->assertRedirect('/admin/sign-in/code');
    });
});

describe('limits on the way in', function () {
    it('sends at most 3 reset emails an hour to one account, and says the same every time', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;

        foreach (range(1, 4) as $request) {
            $browser->post('/admin/password/forgot', ['email' => adminEmail($staffId)])->assertRedirect();
        }

        expect(RecordingSecurityMessages::installed()->passwordResets)->toHaveCount(3);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(61));
        $browser->post('/admin/password/forgot', ['email' => adminEmail($staffId)]);

        expect(RecordingSecurityMessages::installed()->passwordResets)->toHaveCount(4);
    });

    it('resends a sign-in code no sooner than a minute later', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        adminPassword($browser, $staffId);

        expect(AdminBrowser::formError($browser->post('/admin/sign-in/code/resend')))->not->toBeNull()
            ->and(adminCodes())->toBe(1);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $browser->post('/admin/sign-in/code/resend')->assertRedirect();

        expect(adminCodes())->toBe(2);
        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');
    });

    it('changes the password only with the current one, keeping sessions as they are otherwise', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        adminSignIn($browser, $staffId);

        $refused = $browser->post('/admin/account/password', ['current_password' => 'not my password', 'password' => 'a brand new long password']);

        expect(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(adminWho($browser))->toBe($staffId)
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('session_version'))->toBe(0);
    });

    it('asks a new password to follow the rules: 12 characters, not leaked', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        adminSignIn($browser, $staffId);

        $short = $browser->post('/admin/account/password', ['current_password' => ADMIN_PASSWORD, 'password' => 'too short']);
        $leaked = $browser->post('/admin/account/password', ['current_password' => ADMIN_PASSWORD, 'password' => FakeBreachList::LEAKED]);

        expect(AdminBrowser::formError($short))->not->toBeNull()
            ->and(AdminBrowser::formError($leaked))->not->toBeNull()
            ->and(adminWho($browser))->toBe($staffId);
    });
});

describe('password reset', function () {
    it('answers the same whether or not the email has an account, and sends only to an active one', function () {
        $browser = new AdminBrowser;
        $known = $browser->post('/admin/password/forgot', ['email' => adminEmail(Fx::staff())]);
        $unknown = $browser->post('/admin/password/forgot', ['email' => 'nobody@example.test']);
        $disabled = $browser->post('/admin/password/forgot', ['email' => adminEmail(Fx::staff(StaffStatus::Disabled))]);

        expect(AdminBrowser::flashed($known, 'status'))->toBe(AdminBrowser::flashed($unknown, 'status'))
            ->and(AdminBrowser::flashed($disabled, 'status'))->toBe(AdminBrowser::flashed($known, 'status'))
            ->and(RecordingSecurityMessages::installed()->passwordResets)->toHaveCount(1);
    });

    it('works 30 minutes, once', function () {
        $staffId = Fx::staff();
        $browser = new AdminBrowser;
        $browser->post('/admin/password/forgot', ['email' => adminEmail($staffId)]);
        $token = RecordingSecurityMessages::installed()->lastPasswordResetToken();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));

        expect(AdminBrowser::formError($browser->post("/admin/password/reset/{$token}", ['password' => 'a brand new long password'])))
            ->toBe((string) __('access::errors.invalid_or_expired_link.detail'));
    });
});

describe('email links while signed in (amendment 31)', function () {
    it('signs the admin session out first, then accepts the invitation and signs the invitee in', function () {
        $adminId = Fx::staff();
        $browser = new AdminBrowser;
        adminSignIn($browser, $adminId);

        app(InviteStaffHandler::class)->handle(new InviteStaff(
            'noura@example.test', 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966501111111', 'en',
            AccessLevel::SelectedStores, [Fx::storeId('sa')], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]),
        ));
        $token = RecordingSecurityMessages::installed()->lastInvitationToken();
        $invitee = (string) DB::table('access.staff_users')->where('email', 'noura@example.test')->value('id');

        $browser->post("/admin/invitation/{$token}", ['password' => ADMIN_PASSWORD, 'phone' => '+966501111111'])->assertRedirect("/admin/invitation/{$token}/code");

        expect(adminWho($browser))->toBeNull()
            ->and(Fx::audits('access.staff_user.signed_out', $adminId))->toBe(1);

        $browser->post("/admin/invitation/{$token}/code", ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        expect(adminWho($browser))->toBe($invitee);
    });

    it('signs the session out first, then confirms an email change', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        $browser = new AdminBrowser;
        adminSignIn($browser, $superAdmin);
        DB::table('access.staff_email_changes')->insert([
            'staff_user_id' => $superAdmin, 'new_email' => 'owner.new@example.test', 'token_hash' => hash('sha256', 'the-link'),
            'expires_at' => now()->addDay(), 'requested_by' => $superAdmin, 'created_at' => now(),
        ]);

        $browser->post('/admin/email-change/the-link')->assertRedirect('/admin/sign-in');

        expect(adminEmail($superAdmin))->toBe('owner.new@example.test')
            ->and(adminWho($browser))->toBeNull();
    });
});

describe('phones at sign-in', function () {
    it('asks a Super Admin whose phone was reset for a new number, and verifies it with the code (amendment 14)', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone(adminEmail($superAdmin)));
        $browser = new AdminBrowser;

        adminPassword($browser, $superAdmin)->assertRedirect('/admin/sign-in/phone');
        $browser->post('/admin/sign-in/phone', ['phone' => '+966 50 777 7777'])->assertRedirect('/admin/sign-in/code');
        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        $row = DB::table('access.staff_users')->where('id', $superAdmin)->first();

        expect(adminWho($browser))->toBe($superAdmin)
            ->and($row?->phone)->toBe('+966507777777')
            ->and($row?->phone_verified_at)->not->toBeNull();
    });

    it('sends the code to a number an admin entered, and the right code verifies it', function () {
        $staffId = Fx::staff();
        DB::table('access.staff_users')->where('id', $staffId)->update(['phone' => '+966508888888', 'phone_verified_at' => null]);
        $browser = new AdminBrowser;

        adminSignIn($browser, $staffId);

        expect(RecordingSecurityMessages::installed()->codes[0]['phone'])->toBe('+966508888888')
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('phone_verified_at'))->not->toBeNull();
    });

    it('refuses a new number another staff member uses', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone(adminEmail($superAdmin)));
        $taken = (string) DB::table('access.staff_users')->where('id', Fx::staff())->value('phone');
        $browser = new AdminBrowser;
        adminPassword($browser, $superAdmin);

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

        $browser->post("/admin/invitation/{$token}", ['password' => ADMIN_PASSWORD, 'phone' => '+966501112233']);
        $browser->post("/admin/invitation/{$token}/code", ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        expect(adminWho($browser))->toBe((string) DB::table('access.staff_users')->where('email', 'owner@example.test')->value('id'));
    });
});

it('logs a failed query without its values (amendment 33)', function (Closure $fail) {
    $log = Log::spy();

    try {
        $fail();
    } catch (QueryException $e) {
        report($e);
        $log->shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => ! str_contains($message, 'secret.person@example.test')
            && ! str_contains($message, '+966509990000') && str_contains($message, 'SQLSTATE'));

        return;
    }

    throw new LogicException('The query should have failed.');
})->with([
    'a row the database refuses' => fn () => DB::table('access.staff_users')->insert(['id' => strtolower((string) Str::ulid()), 'email' => 'secret.person@example.test', 'phone' => '+966509990000']),
    'a value already taken' => function (): void {
        DB::table('access.staff_users')->where('id', Fx::staff())->update(['email' => 'secret.person@example.test']);
        DB::table('access.staff_users')->where('id', Fx::staff())->update(['email' => 'secret.person@example.test', 'phone' => '+966509990000']);
    },
]);
