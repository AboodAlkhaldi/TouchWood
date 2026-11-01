<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdmin;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdminHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomer;
use Modules\Access\Application\Command\RegisterCustomer\RegisterCustomerHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Staff\Invitations;
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
