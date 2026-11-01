<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitation;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitationHandler;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmail;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmailHandler;
use Modules\Access\Application\Command\ConfirmStaffEmailChange\ConfirmStaffEmailChange;
use Modules\Access\Application\Command\ConfirmStaffEmailChange\ConfirmStaffEmailChangeHandler;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdmin;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdminHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomer;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomerHandler;
use Modules\Access\Application\Command\ResendStaffInvitation\ResendStaffInvitation;
use Modules\Access\Application\Command\ResendStaffInvitation\ResendStaffInvitationHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\Invitations;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Exception\InvitationNotSent;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\AuthenticatorFixtures as App;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\CapturedEmails;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A session that survives a redirect: phpunit.xml forces "array", which keeps nothing between
    // requests, and every form here is answered with one.
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Exceptions::fake();
});

/*
| Amendment 93: the email provider fails after the row has committed. Nothing is undone, nobody gets
| an error page, a failure reaches the log, and nobody is told an invitation went.
*/

function messageFailureOutbox(): RecordingSecurityMessages
{
    return RecordingSecurityMessages::installed();
}

function messageFailureProviderIsReported(): void
{
    Exceptions::assertReported(static fn (RuntimeException $failed): bool => $failed->getMessage() === 'The email provider is away.');
}

/**
 * @return TestResponse<Response>
 */
function messageFailureRegister(AdminBrowser $browser, string $email = 'new@example.test'): TestResponse
{
    return $browser->post('/sa/en/account/register', [
        'email' => $email,
        'password' => Fx::CUSTOMER_PASSWORD,
        'first_name' => 'Noura',
        'last_name' => 'Saleh',
        'account_type' => 'individual',
        'locale' => 'en',
        'terms' => '1',
    ]);
}

/**
 * An admin of the Saudi store who may invite and correct an address, signed in completely.
 */
function messageFailureAdmin(): AdminBrowser
{
    $adminId = Fx::staffWith(
        [AccessPermissions::STAFF_VIEW, AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_UPDATE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE],
        ['sa'],
        RoleLevel::Admin,
    );
    $browser = new AdminBrowser('10.6.1.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $adminId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => messageFailureOutbox()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * @return TestResponse<Response>
 */
function messageFailureInvite(AdminBrowser $browser, string $email = 'noura@example.test'): TestResponse
{
    return $browser->post('/admin/staff/invite', [
        'email' => $email,
        'first_name' => 'Noura',
        'last_name' => 'Saleh',
        'job_title' => 'Store manager',
        'date_of_birth' => '1995-04-11',
        'country' => 'SA',
        'address' => 'Riyadh',
        'phone' => Fx::phone(),
        'locale' => 'en',
        'access_level' => 'SELECTED_STORES',
        'store_ids' => [Fx::storeId('sa')],
        'permissions' => [PlatformPermissions::STORE_UPDATE],
        'exceptions' => [],
    ]);
}

function messageFailureStaffId(string $email): string
{
    return (string) (DB::table('access.staff_users')->where('email', $email)->value('id') ?? throw new LogicException("No staff member {$email}."));
}

/**
 * @param  array<string, string>  $parameters
 */
function messageFailureConsole(string $command, array $parameters): PendingCommand
{
    $pending = artisan($command, $parameters);

    return $pending instanceof PendingCommand ? $pending : throw new LogicException('Expected a pending command');
}

describe('registering while the provider is down', function () {
    it('keeps the account, signs the customer in, logs the failure, and the link can be resent', function () {
        messageFailureOutbox()->emailsFail = true;
        $browser = new AdminBrowser;

        $answer = messageFailureRegister($browser)->assertRedirect('/sa/en');

        expect(AdminBrowser::flashed($answer, 'status'))->toBe(__('access::auth.registered'))
            ->and(DB::table('access.customers')->where('email', 'new@example.test')->exists())->toBeTrue()
            ->and(messageFailureOutbox()->emailVerifications)->toBe([]);
        messageFailureProviderIsReported();

        // Signed in: the resend route is only for someone signed in, and sends once the provider is back.
        messageFailureOutbox()->emailsFail = false;
        $browser->post('/sa/en/account/verify-email/resend')->assertRedirect();

        expect(messageFailureOutbox()->emailVerifications)->toHaveCount(1)
            ->and(messageFailureOutbox()->emailVerifications[0]['to'])->toBe('new@example.test');

        // The link it sent confirms the address.
        $browser->get(messageFailureOutbox()->emailVerifications[0]['link'])->assertRedirect();

        expect(DB::table('access.customers')->where('email', 'new@example.test')->value('email_verified_at'))->not->toBeNull();
    });

    it('holds through the real sender: Ops records the failure, and it reaches the log', function () {
        app()->forgetInstance(SecurityMessages::class);
        $emails = CapturedEmails::install();
        $emails->fails = true;
        $browser = new AdminBrowser;

        messageFailureRegister($browser)->assertRedirect('/sa/en');

        Exceptions::assertReported(ProviderFailed::class);
        expect(DB::table('access.customers')->where('email', 'new@example.test')->exists())->toBeTrue()
            ->and(DB::table('ops.sent_messages')->where('status', 'FAILED')->count())->toBe(1)
            ->and($emails->sent)->toBe([]);

        $emails->fails = false;
        $browser->post('/sa/en/account/verify-email/resend')->assertRedirect();

        expect($emails->to())->toBe(['new@example.test']);
    });

    it('sends nothing when the registration rolls back', function () {
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(fn () => DB::transaction(function (): void {
            Fx::inStoreCode('sa', fn (): string => app(RegisterCustomerHandler::class)->handle(
                new RegisterCustomer('gone@example.test', Fx::CUSTOMER_PASSWORD, 'Noura', 'Saleh', 'individual', 'en', true),
            ));

            throw new LogicException('Rolled back after the handler.');
        }))->toThrow(LogicException::class);

        expect(DB::table('access.customers')->where('email', 'gone@example.test')->exists())->toBeFalse()
            ->and(messageFailureOutbox()->emailVerifications)->toBe([]);
        Exceptions::assertNothingReported();
    });
});

describe('inviting while the provider is down', function () {
    it('keeps the member, tells the admin in amber, logs the failure, and Resend sends later', function () {
        $browser = messageFailureAdmin();
        messageFailureOutbox()->emailsFail = true;

        $answer = messageFailureInvite($browser);
        $staffId = messageFailureStaffId('noura@example.test');
        $answer->assertRedirect("/admin/staff/{$staffId}");

        expect(AdminBrowser::flashed($answer, 'warning'))->toBe(__('access::staff.invitation_not_sent'))
            ->and(AdminBrowser::flashed($answer, 'status'))->toBeNull()
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('status'))->toBe(StaffStatus::Invited->value)
            ->and(messageFailureOutbox()->invitations)->toBe([]);
        messageFailureProviderIsReported();
        Exceptions::assertNotReported(InvitationNotSent::class);

        // The page the admin lands on carries it to the toast, and offers the button it names.
        $browser->get("/admin/staff/{$staffId}")->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('flash.warning', __('access::staff.invitation_not_sent'))
            ->where('flash.status', null)
            ->where('mayResendInvitation', true)
        );

        // Resending while it is still down says the same, not an error page.
        $again = $browser->post("/admin/staff/{$staffId}/invitation/resend");
        $again->assertRedirect();

        expect(AdminBrowser::flashed($again, 'warning'))->toBe(__('access::staff.invitation_resend_failed'))
            ->and(AdminBrowser::flashed($again, 'status'))->toBeNull()
            ->and(messageFailureOutbox()->invitations)->toBe([]);

        // Back up: the resend sends, the link is the one saved, and it says so.
        messageFailureOutbox()->emailsFail = false;
        $sent = $browser->post("/admin/staff/{$staffId}/invitation/resend");

        expect(AdminBrowser::flashed($sent, 'status'))->toBe(__('access::staff.invitation_resent'))
            ->and(AdminBrowser::flashed($sent, 'warning'))->toBeNull()
            ->and(messageFailureOutbox()->invitations)->toHaveCount(1)
            ->and(DB::table('access.staff_invitations')->where('staff_user_id', $staffId)->value('token_hash'))
            ->toBe(hash('sha256', messageFailureOutbox()->lastInvitationToken()));
    });

    it('still says sent when it was sent', function () {
        $browser = messageFailureAdmin();

        $answer = messageFailureInvite($browser);

        expect(AdminBrowser::flashed($answer, 'status'))->toBe(__('access::staff.invitation_sent'))
            ->and(AdminBrowser::flashed($answer, 'warning'))->toBeNull()
            ->and(messageFailureOutbox()->invitations)->toHaveCount(1);
        Exceptions::assertNothingReported();
    });

    it('moves a corrected address and says the invitation did not go', function () {
        $browser = messageFailureAdmin();
        messageFailureInvite($browser, 'mistyped@example.test');
        $staffId = messageFailureStaffId('mistyped@example.test');
        messageFailureOutbox()->emailsFail = true;

        $answer = $browser->post("/admin/staff/{$staffId}/email", ['email' => 'noura@example.test']);

        expect(AdminBrowser::flashed($answer, 'warning'))->toBe(__('access::staff.email_changed_invitation_not_sent'))
            ->and(AdminBrowser::flashed($answer, 'status'))->toBeNull()
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('email'))->toBe('noura@example.test')
            ->and(messageFailureOutbox()->invitations)->toHaveCount(1);
        messageFailureProviderIsReported();
    });

    it('answers with the member it saved, and reads the words in Arabic too', function () {
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);
        messageFailureOutbox()->emailsFail = true;

        try {
            app(InviteStaffHandler::class)->handle(new InviteStaff(
                'noura@example.test', 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966501111111', 'en',
                AccessLevel::SelectedStores, [Fx::storeId('sa')], savedRoleId: $roleId,
            ));
            $saved = null;
        } catch (InvitationNotSent $notSent) {
            $saved = $notSent->staffId;
        }

        expect($saved)->toBe(messageFailureStaffId('noura@example.test'));

        foreach (['invitation_not_sent', 'invitation_resend_failed', 'email_changed_invitation_not_sent'] as $word) {
            expect(__("access::staff.{$word}", [], 'ar'))->toContain('«إعادة إرسال الدعوة»')
                ->and(__("access::staff.{$word}", [], 'en'))->toContain(__('access::staff.resend_invitation', [], 'en'));
        }
    });

    it('sends nothing when the invitation rolls back', function () {
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);

        expect(fn () => DB::transaction(function () use ($roleId): void {
            app(InviteStaffHandler::class)->handle(new InviteStaff(
                'gone@example.test', 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966501111111', 'en',
                AccessLevel::SelectedStores, [Fx::storeId('sa')], savedRoleId: $roleId,
            ));

            throw new LogicException('Rolled back after the handler.');
        }))->toThrow(LogicException::class);

        expect(DB::table('access.staff_users')->where('email', 'gone@example.test')->exists())->toBeFalse()
            ->and(messageFailureOutbox()->invitations)->toBe([]);
        Exceptions::assertNothingReported();
    });

    it('lets the commit\'s other callbacks run after a failed email', function () {
        $staff = app(StaffUserRepository::class)->byId(Fx::staff(StaffStatus::Invited)) ?? throw new LogicException('No staff member.');
        messageFailureOutbox()->emailsFail = true;
        $ran = false;

        DB::transaction(function () use ($staff, &$ran): void {
            app(Invitations::class)->send($staff, null);
            DB::afterCommit(function () use (&$ran): void {
                $ran = true;
            });
        });

        expect($ran)->toBeTrue();
        messageFailureProviderIsReported();
    });
});

describe('the console while the provider is down', function () {
    it('says a new Super Admin was not emailed, exits non-zero, and the resend command sends later', function () {
        messageFailureOutbox()->emailsFail = true;

        messageFailureConsole('access:super-admin:create', [
            'email' => 'owner@example.test', 'first_name' => 'Abood', 'last_name' => 'Owner', '--job-title' => 'Founder',
            '--date-of-birth' => '1995-01-01', '--country' => 'SA', '--phone' => '+966501112233', '--locale' => 'en',
        ])->expectsOutputToContain('could not be sent')->doesntExpectOutputToContain('the invitation was emailed')->assertFailed()->run();

        expect(DB::table('access.staff_users')->where('email', 'owner@example.test')->value('status'))->toBe(StaffStatus::Invited->value)
            ->and(messageFailureOutbox()->invitations)->toBe([]);
        messageFailureProviderIsReported();

        messageFailureConsole('access:super-admin:resend-invitation', ['email' => 'owner@example.test'])
            ->expectsOutputToContain('could not be sent')->doesntExpectOutputToContain('was emailed')->assertFailed()->run();

        messageFailureOutbox()->emailsFail = false;

        messageFailureConsole('access:super-admin:resend-invitation', ['email' => 'owner@example.test'])
            ->expectsOutputToContain('was emailed')->assertSuccessful()->run();

        expect(messageFailureOutbox()->invitations)->toHaveCount(1);
    });

    it('says a promoted member who never accepted was not emailed, and leaves them promoted', function () {
        $staffId = Fx::staff(StaffStatus::Invited);
        messageFailureOutbox()->emailsFail = true;

        expect(fn () => app(CreateSuperAdminHandler::class)->handle(new CreateSuperAdmin((string) DB::table('access.staff_users')->where('id', $staffId)->value('email'))))
            ->toThrow(InvitationNotSent::class)
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('is_super_admin'))->toBeTrue();
        messageFailureProviderIsReported();
    });
});

/**
 * The admin of the Saudi store holding exactly these permissions, signed in completely.
 *
 * @param  list<string>  $permissions
 */
function messageFailureAdminHolding(array $permissions): AdminBrowser
{
    return messageFailureSignIn(Fx::staffWith($permissions, ['sa'], RoleLevel::Admin));
}

function messageFailureSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.6.2.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => messageFailureOutbox()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * Someone a store's admin manages, in the Saudi store, with this status.
 */
function messageFailureMember(StaffStatus $status): string
{
    $staffId = Fx::staff($status);
    Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE]), ['sa']);

    return $staffId;
}

function messageFailureAcceptAs(string $token): void
{
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
    app(AcceptStaffInvitationHandler::class)->handle(new AcceptStaffInvitation($token, 'a long enough password', '+966501111111'));
}

function messageFailureInviteDirectly(): string
{
    Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_UPDATE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);

    return app(InviteStaffHandler::class)->handle(new InviteStaff(
        'noura@example.test', 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966501111111', 'en',
        AccessLevel::SelectedStores, [Fx::storeId('sa')], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]),
    ));
}

describe('a customer resending their link', function () {
    it('tells the truth when the send fails, logs it, and does not count the press', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        $browser->post('/sa/en/account/sign-in', [
            'email' => (string) DB::table('access.customers')->where('id', $customerId)->value('email'),
            'password' => Fx::CUSTOMER_PASSWORD,
        ])->assertRedirect('/sa/en');
        $fromRegistering = count(messageFailureOutbox()->emailVerifications);
        messageFailureOutbox()->emailsFail = true;

        // More presses than the limit allows, none of them counted.
        foreach (range(1, 5) as $press) {
            $failed = $browser->post('/sa/en/account/verify-email/resend');

            expect(AdminBrowser::formError($failed))->toBe(__('access::errors.verification_email_not_sent.detail'))
                ->and(AdminBrowser::flashed($failed, 'status'))->toBeNull();
        }

        messageFailureProviderIsReported();

        // Back up: the 3 an hour are all still there, and the fourth is refused as ever.
        messageFailureOutbox()->emailsFail = false;

        foreach (range(1, 3) as $press) {
            $sent = $browser->post('/sa/en/account/verify-email/resend');

            expect(AdminBrowser::flashed($sent, 'status'))->toBe(__('access::auth.verification_sent'));
        }

        $refused = $browser->post('/sa/en/account/verify-email/resend');

        expect(AdminBrowser::flashed($refused, 'status'))->toBeNull()
            ->and(AdminBrowser::formError($refused))->not->toBeNull()
            ->and(messageFailureOutbox()->emailVerifications)->toHaveCount($fromRegistering + 3);
    });

    it('counts a press that is sent before a later one fails', function () {
        $customerId = Fx::customer();
        $browser = new AdminBrowser;
        $browser->post('/sa/en/account/sign-in', [
            'email' => (string) DB::table('access.customers')->where('id', $customerId)->value('email'),
            'password' => Fx::CUSTOMER_PASSWORD,
        ]);
        $fromRegistering = count(messageFailureOutbox()->emailVerifications);

        foreach ([false, true, false, true, false, false] as $down) {
            messageFailureOutbox()->emailsFail = $down;
            $browser->post('/sa/en/account/verify-email/resend');
        }

        // Three sent and two given back; the sixth press is the fourth to count, and is refused.
        expect(messageFailureOutbox()->emailVerifications)->toHaveCount($fromRegistering + 3);
    });
});

describe('a failed resend, and the old link', function () {
    it('kills the old link after a failed resend, says so, and audits both truths', function () {
        $staffId = messageFailureInviteDirectly();
        $old = messageFailureOutbox()->lastInvitationToken();
        messageFailureOutbox()->emailsFail = true;

        expect(fn () => app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($staffId)))->toThrow(InvitationNotSent::class)
            ->and(Fx::audits('access.staff_user.invitation_resent', $staffId))->toBe(1)
            ->and(Fx::audits('access.staff_user.invitation_not_sent', $staffId))->toBe(1)
            ->and(fn () => messageFailureAcceptAs($old))->toThrow(InvalidOrExpiredLink::class);

        foreach (['en' => 'the earlier link no longer works', 'ar' => 'الرابط السابق لم يعد يعمل'] as $locale => $words) {
            expect(__('access::staff.invitation_resend_failed', [], $locale))->toContain($words)
                ->and(__('access::staff.email_changed_invitation_not_sent', [], $locale))->toContain($words);
        }
    });

    it('kills the old link after a failed email change on someone invited', function () {
        $staffId = messageFailureInviteDirectly();
        $old = messageFailureOutbox()->lastInvitationToken();
        messageFailureOutbox()->emailsFail = true;

        expect(fn () => app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'corrected@example.test')))->toThrow(InvitationNotSent::class)
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('email'))->toBe('corrected@example.test')
            ->and(Fx::audits('access.staff_user.invitation_not_sent', $staffId))->toBe(1)
            ->and(fn () => messageFailureAcceptAs($old))->toThrow(InvalidOrExpiredLink::class);
    });

    it('writes no failure entry when the email went', function () {
        $staffId = messageFailureInviteDirectly();

        app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($staffId));

        expect(Fx::audits('access.staff_user.invitation_resent', $staffId))->toBe(1)
            ->and(Fx::audits('access.staff_user.invitation_not_sent', $staffId))->toBe(0);
    });
});

describe('the staff sign-in notices while the provider is down', function () {
    it('still signs in a Super Admin whose phone was replaced', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        DB::table('access.staff_users')->where('id', $superAdmin)->update(['phone' => '+971501234567']);
        $browser = new AdminBrowser;
        $browser->post('/admin/sign-in', [
            'email' => (string) DB::table('access.staff_users')->where('id', $superAdmin)->value('email'),
            'password' => Fx::STAFF_PASSWORD,
        ])->assertRedirect('/admin/sign-in/phone');
        $browser->post('/admin/sign-in/phone', ['phone' => '+966507777777'])->assertRedirect('/admin/sign-in/code');
        messageFailureOutbox()->emailsFail = true;

        $browser->post('/admin/sign-in/code', ['code' => messageFailureOutbox()->lastCode()])->assertRedirect('/admin');

        expect(DB::table('access.staff_users')->where('id', $superAdmin)->value('phone'))->toBe('+966507777777')
            ->and(messageFailureOutbox()->phoneChanges)->toBe([]);
        messageFailureProviderIsReported();
        $browser->get('/admin')->assertOk();
    });

    it('answers the wrong-code refusal when the notice of used-up tries fails', function () {
        App::methods(sms: false, authenticator: true);
        $staffId = Fx::staff();
        $app = App::giveApp($staffId);
        $tries = app(StaffSecuritySettings::class)->codeAttempts();
        $browser = new AdminBrowser;
        $browser->post('/admin/sign-in', [
            'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
            'password' => Fx::STAFF_PASSWORD,
        ])->assertRedirect('/admin/sign-in/app');
        messageFailureOutbox()->emailsFail = true;

        for ($i = 1; $i < $tries; $i++) {
            $browser->post('/admin/sign-in/app', ['code' => App::wrongCode($app['secret'])]);
        }

        $last = $browser->post('/admin/sign-in/app', ['code' => App::wrongCode($app['secret'])]);

        expect(AdminBrowser::formError($last))->toBe(__('access::errors.app_code_tries_used_up.detail'))
            ->and(Fx::audits('access.staff_user.app_code_tries_used_up', $staffId))->toBe(1)
            ->and(messageFailureOutbox()->appCodeFailures)->toBe([]);
        messageFailureProviderIsReported();
    });
});

describe('an email change to an active member while the provider is down', function () {
    it('keeps the link saved, logs the failure, and tells the admin what to do', function () {
        $staffId = messageFailureMember(StaffStatus::Active);
        $browser = messageFailureAdminHolding([AccessPermissions::STAFF_VIEW, AccessPermissions::STAFF_UPDATE, PlatformPermissions::STORE_UPDATE]);
        messageFailureOutbox()->emailsFail = true;

        $answer = $browser->post("/admin/staff/{$staffId}/email", ['email' => 'new.address@example.test']);
        $answer->assertRedirect();

        expect(AdminBrowser::flashed($answer, 'warning'))->toBe(__('access::staff.email_link_not_sent'))
            ->and(AdminBrowser::flashed($answer, 'status'))->toBeNull()
            ->and(DB::table('access.staff_email_changes')->where('staff_user_id', $staffId)->exists())->toBeTrue()
            ->and(messageFailureOutbox()->emailChanges)->toBe([]);
        messageFailureProviderIsReported();

        // Asking again, as the words say, sends a link that works.
        messageFailureOutbox()->emailsFail = false;
        $again = $browser->post("/admin/staff/{$staffId}/email", ['email' => 'new.address@example.test']);

        expect(AdminBrowser::flashed($again, 'status'))->toBe(__('access::staff.email_link_sent'))
            ->and(messageFailureOutbox()->emailChanges)->toHaveCount(1);

        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
        app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange(messageFailureOutbox()->lastEmailChangeToken()));

        expect(DB::table('access.staff_users')->where('id', $staffId)->value('email'))->toBe('new.address@example.test');
    });

    it('tells a Super Admin changing their own address the same', function () {
        // Only a Super Admin changes their own: anyone else's goes through an admin.
        $staffId = Fx::staff(superAdmin: true);
        $browser = messageFailureSignIn($staffId);
        messageFailureOutbox()->emailsFail = true;

        $answer = $browser->post('/admin/account/email', ['email' => 'mine@example.test']);
        $answer->assertRedirect();

        expect(AdminBrowser::flashed($answer, 'warning'))->toBe(__('access::account.email_change_not_sent'))
            ->and(AdminBrowser::flashed($answer, 'status'))->toBeNull()
            ->and(AdminBrowser::formError($answer))->toBeNull()
            ->and(DB::table('access.staff_email_changes')->where('staff_user_id', $staffId)->exists())->toBeTrue();
        messageFailureProviderIsReported();
    });
});

describe('an admin who cannot press Resend Invitation', function () {
    it('sends an admin who may invite but not view to a page they may see, in the right words', function () {
        $browser = messageFailureAdminHolding([AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);
        messageFailureOutbox()->emailsFail = true;

        $answer = messageFailureInvite($browser);
        $answer->assertRedirect('/admin/staff/invite');

        expect(AdminBrowser::flashed($answer, 'warning'))->toBe(__('access::staff.invitation_not_sent_ask'))
            ->and(__('access::staff.invitation_not_sent_ask'))->toContain('invite staff')
            ->and(__('access::staff.invitation_not_sent_ask'))->not->toContain(__('access::staff.resend_invitation'));

        $staffId = messageFailureStaffId('noura@example.test');
        $browser->get('/admin/staff/invite')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('flash.warning', __('access::staff.invitation_not_sent_ask'))
        );
        $browser->get("/admin/staff/{$staffId}")->assertNotFound();
    });

    it('sends the same admin to the form on success too, not to a page that answers not found', function () {
        $browser = messageFailureAdminHolding([AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);

        $answer = messageFailureInvite($browser);
        $answer->assertRedirect('/admin/staff/invite');

        expect(AdminBrowser::flashed($answer, 'status'))->toBe(__('access::staff.invitation_sent'));
    });

    it('tells an admin who may update but not invite that someone else must resend it', function () {
        $staffId = messageFailureMember(StaffStatus::Invited);
        $browser = messageFailureAdminHolding([AccessPermissions::STAFF_VIEW, AccessPermissions::STAFF_UPDATE, PlatformPermissions::STORE_UPDATE]);
        messageFailureOutbox()->emailsFail = true;

        $browser->get("/admin/staff/{$staffId}")->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('mayChangeEmail', true)
            ->where('mayResendInvitation', false)
        );

        $answer = $browser->post("/admin/staff/{$staffId}/email", ['email' => 'corrected@example.test']);

        expect(AdminBrowser::flashed($answer, 'warning'))->toBe(__('access::staff.email_changed_invitation_not_sent_ask'))
            ->and(__('access::staff.email_changed_invitation_not_sent_ask'))->toContain('invite staff')
            ->and(__('access::staff.email_changed_invitation_not_sent_ask'))->not->toContain(__('access::staff.resend_invitation'))
            ->and(DB::table('access.staff_users')->where('id', $staffId)->value('email'))->toBe('corrected@example.test');
    });
});

describe('through Ops\' real sender', function () {
    beforeEach(function () {
        app()->forgetInstance(SecurityMessages::class);
    });

    it('records an invitation that failed against the staff member', function () {
        $emails = CapturedEmails::install();
        $emails->fails = true;
        DB::table('ops.sent_messages')->delete();

        try {
            messageFailureInviteDirectly();
            $staffId = null;
        } catch (InvitationNotSent $notSent) {
            $staffId = $notSent->staffId;
        }

        expect($staffId)->not->toBeNull()
            ->and(DB::table('ops.sent_messages')->where('status', 'FAILED')->where('staff_id', $staffId)->count())->toBe(1)
            ->and(DB::table('ops.sent_messages')->where('status', 'SENT')->count())->toBe(0)
            ->and($emails->sent)->toBe([]);
        Exceptions::assertReported(ProviderFailed::class);
    });

    it('records a console invitation that failed, with no store, and the console says so', function () {
        $emails = CapturedEmails::install();
        $emails->fails = true;
        DB::table('ops.sent_messages')->delete();

        messageFailureConsole('access:super-admin:create', [
            'email' => 'owner@example.test', 'first_name' => 'Abood', 'last_name' => 'Owner', '--job-title' => 'Founder',
            '--date-of-birth' => '1995-01-01', '--country' => 'SA', '--phone' => '+966501112233', '--locale' => 'en',
        ])->expectsOutputToContain('could not be sent')->assertFailed()->run();

        $staffId = messageFailureStaffId('owner@example.test');

        expect(DB::table('ops.sent_messages')->where('status', 'FAILED')->where('staff_id', $staffId)->whereNull('store_id')->count())->toBe(1);
        Exceptions::assertReported(ProviderFailed::class);
    });
});

it('names the badge a customer must press, in both languages', function () {
    // auth.registered sends them to the header's badge by its label: renaming one must fail here.
    foreach (['en', 'ar'] as $locale) {
        expect(__('access::auth.registered', [], $locale))->toContain(__('access::auth.verify_pending', [], $locale));
    }
});
