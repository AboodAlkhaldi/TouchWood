<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitation;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitationHandler;
use Modules\Access\Application\Command\CancelExpiredSuperAdminInvitations\CancelExpiredSuperAdminInvitations;
use Modules\Access\Application\Command\CancelExpiredSuperAdminInvitations\CancelExpiredSuperAdminInvitationsHandler;
use Modules\Access\Application\Command\CancelSuperAdminInvitation\CancelSuperAdminInvitation;
use Modules\Access\Application\Command\CancelSuperAdminInvitation\CancelSuperAdminInvitationHandler;
use Modules\Access\Application\Command\ConfirmStaffInvitation\ConfirmStaffInvitation;
use Modules\Access\Application\Command\ConfirmStaffInvitation\ConfirmStaffInvitationHandler;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdmin;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdminHandler;
use Modules\Access\Application\Command\EnableStaff\EnableStaff;
use Modules\Access\Application\Command\EnableStaff\EnableStaffHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\ResendSuperAdminInvitation\ResendSuperAdminInvitation;
use Modules\Access\Application\Command\ResendSuperAdminInvitation\ResendSuperAdminInvitationHandler;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhone;
use Modules\Access\Application\Command\ResetSuperAdminPhone\ResetSuperAdminPhoneHandler;
use Modules\Access\Application\Command\RevokeSuperAdmin\RevokeSuperAdmin;
use Modules\Access\Application\Command\RevokeSuperAdmin\RevokeSuperAdminHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\LastSuperAdmin;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Access\Public\Events\StaffActivated;
use Modules\Access\Public\Events\StaffDisabled;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function superAdminMessages(): RecordingSecurityMessages
{
    return RecordingSecurityMessages::installed();
}

function newSuperAdmin(string $email = 'owner@example.test', ?string $phone = '+966501112233'): CreateSuperAdmin
{
    return new CreateSuperAdmin($email, 'Abood', 'Owner', 'Founder', '1995-01-01', 'SA', $phone, 'ar');
}

/**
 * @return array<string, mixed>
 */
function staffByEmail(string $email): array
{
    return (array) DB::table('access.staff_users')->whereRaw('lower(email) = lower(?)', [$email])->first();
}

function emailOf(string $staffId): string
{
    return (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
}

/**
 * @param  array<string, string>  $parameters
 */
function superAdminConsole(string $command, array $parameters): PendingCommand
{
    $pending = artisan($command, $parameters);

    return $pending instanceof PendingCommand ? $pending : throw new LogicException('Expected a pending command');
}

describe('creating a Super Admin', function () {
    it('refuses an address a customer holds, from the console too', function () {
        Fx::customer('shared@example.test');

        // One email, one account (amendment 13): the console is no exception (amendment 46(f)).
        expect(fn () => app(CreateSuperAdminHandler::class)->handle(newSuperAdmin('SHARED@example.test')))
            ->toThrow(StaffEmailInUse::class)
            ->and(DB::table('access.staff_users')->whereRaw('lower(email) = ?', ['shared@example.test'])->exists())->toBeFalse();
    });

    it('invites a new account with the whole profile, as a Super Admin with no role', function () {
        expect(app(CreateSuperAdminHandler::class)->handle(newSuperAdmin()))->toBe(CreateSuperAdminHandler::CREATED);

        $row = staffByEmail('owner@example.test');

        expect($row['status'])->toBe('INVITED')
            ->and($row['is_super_admin'])->toBeTrue()
            ->and($row['password'])->toBeNull()
            ->and($row['phone'])->toBe('+966501112233')
            ->and(DB::table('access.role_assignments')->where('staff_user_id', $row['id'])->exists())->toBeFalse()
            ->and(DB::table('access.staff_notification_preferences')->where('staff_user_id', $row['id'])->count())->toBe(4)
            ->and(superAdminMessages()->invitations)->toHaveCount(1)
            ->and(superAdminMessages()->invitations[0]['locale'])->toBe('ar');
    });

    it('needs the whole profile and a free phone for a new account', function (CreateSuperAdmin $command, string $error) {
        Fx::staff();
        DB::table('access.staff_users')->limit(1)->update(['phone' => '+966509990000']);
        Fx::withoutStaffUniqueIndexes();

        expect(fn () => app(CreateSuperAdminHandler::class)->handle($command))->toThrow($error)
            ->and(superAdminMessages()->invitations)->toBe([]);
    })->with([
        'no names' => [new CreateSuperAdmin('owner@example.test', phone: '+966501112233', jobTitle: 'Founder', dateOfBirth: '1995-01-01', country: 'SA'), InvalidAccessAttribute::class],
        'no phone' => [fn () => newSuperAdmin(phone: null), InvalidAccessAttribute::class],
        'a taken phone' => [fn () => newSuperAdmin(phone: '+966509990000'), PhoneAlreadyInUse::class],
    ]);

    it('promotes an existing staff member, whose role ends, and keeps their profile', function () {
        $staffId = Fx::staff(firstName: 'Sara');
        Fx::personalRole($staffId, [PlatformPermissions::STORE_UPDATE]);

        $result = app(CreateSuperAdminHandler::class)->handle(new CreateSuperAdmin(strtoupper(emailOf($staffId))));

        expect($result)->toBe(CreateSuperAdminHandler::PROMOTED)
            ->and(staffByEmail(emailOf($staffId))['is_super_admin'])->toBeTrue()
            ->and(staffByEmail(emailOf($staffId))['first_name'])->toBe('Sara')
            ->and(DB::table('access.role_assignments')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(DB::table('access.roles')->where('kind', 'PERSONAL')->exists())->toBeFalse()
            ->and(Fx::audits('access.staff_user.role_removed', $staffId))->toBe(1)
            ->and(superAdminMessages()->invitations)->toBe([]);

        Fx::actAsStaff($staffId);
        expect(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('ae')))->toBeTrue();
    });

    it('enables a disabled staff member it promotes, and re-invites one who never accepted', function () {
        Event::fake([StaffActivated::class]);
        $disabled = Fx::staff(StaffStatus::Disabled);
        $invited = Fx::staff(StaffStatus::Invited);

        app(CreateSuperAdminHandler::class)->handle(new CreateSuperAdmin(emailOf($disabled)));
        app(CreateSuperAdminHandler::class)->handle(new CreateSuperAdmin(emailOf($invited)));

        expect(staffByEmail(emailOf($disabled))['status'])->toBe('ACTIVE')
            ->and(staffByEmail(emailOf($invited))['status'])->toBe('INVITED')
            ->and(superAdminMessages()->invitations)->toHaveCount(1)
            ->and(superAdminMessages()->invitations[0]['to'])->toBe(emailOf($invited));
        Event::assertDispatchedTimes(StaffActivated::class, 1);
    });
});

it('runs only from the console: never for a Super Admin in the panel, nor a job queued on their behalf', function (Closure $actor, Closure $run) {
    Fx::staff(superAdmin: true);
    $target = Fx::staff(superAdmin: true);
    Fx::actAs($actor(Fx::staff(superAdmin: true)));

    expect(fn () => $run(emailOf($target)))->toThrow(Unauthorized::class)
        ->and(superAdminMessages()->invitations)->toBe([]);
})->with([
    'a Super Admin' => fn (string $superAdmin) => Actor::staff($superAdmin),
    'their queued job' => fn (string $superAdmin) => Actor::system(Actor::staff($superAdmin)),
])->with([
    'create' => fn (string $email) => app(CreateSuperAdminHandler::class)->handle(newSuperAdmin('someone@example.test')),
    'revoke' => fn (string $email) => app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin($email)),
    'reset the phone' => fn (string $email) => app(ResetSuperAdminPhoneHandler::class)->handle(new ResetSuperAdminPhone($email)),
    'resend an invitation' => fn (string $email) => app(ResendSuperAdminInvitationHandler::class)->handle(new ResendSuperAdminInvitation($email)),
    'cancel an invitation' => fn (string $email) => app(CancelSuperAdminInvitationHandler::class)->handle(new CancelSuperAdminInvitation($email)),
    'sweep expired invitations' => fn (string $email) => app(CancelExpiredSuperAdminInvitationsHandler::class)->handle(new CancelExpiredSuperAdminInvitations),
]);

describe('revoking a Super Admin', function () {
    it('closes the account and frees its email at once (amendment 45)', function () {
        Event::fake([StaffDisabled::class]);
        Fx::staff(superAdmin: true);
        $revoked = Fx::staff(superAdmin: true);
        $email = emailOf($revoked);
        Fx::warmCache($revoked);

        app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin($email));

        Fx::actAsStaff($revoked);
        expect(staffByEmail($email)['is_super_admin'])->toBeFalse()
            // Revoking is done from the console, so the title and the account go together: nobody
            // is left without a role (owner, 2026-09-20).
            ->and(staffByEmail($email)['status'])->toBe('CANCELLED')
            ->and(staffByEmail($email)['password'])->toBeNull()
            ->and(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa')))->toBeFalse()
            ->and(Fx::audits('access.staff_user.super_admin_revoked', $revoked))->toBe(1);
        Event::assertDispatched(StaffDisabled::class);
    });

    it('frees the email for a fresh invitation', function () {
        Fx::staff(superAdmin: true);
        $revoked = Fx::staff(superAdmin: true);
        $email = emailOf($revoked);
        app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin($email));

        // The same person may be invited again, as a new account — the way back is an invitation,
        // not an "enable" (owner, 2026-09-20).
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);

        $invited = app(InviteStaffHandler::class)->handle(new InviteStaff(
            $email, 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966501111111', 'en',
            AccessLevel::SelectedStores, [Fx::storeId('sa')],
            savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]),
        ));

        expect($invited)->not->toBe($revoked)
            ->and(DB::table('access.staff_users')->where('id', $invited)->value('status'))->toBe('INVITED');
    });

    it('cancels and frees a Super Admin who never accepted (amendment 30)', function () {
        Fx::staff(superAdmin: true);
        app(CreateSuperAdminHandler::class)->handle(newSuperAdmin());
        $invited = (string) staffByEmail('owner@example.test')['id'];

        app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin('owner@example.test'));

        expect(staffByEmail('owner@example.test')['status'])->toBe('CANCELLED')
            ->and(staffByEmail('owner@example.test')['is_super_admin'])->toBeFalse()
            ->and(DB::table('access.staff_invitations')->where('staff_user_id', $invited)->exists())->toBeFalse()
            ->and(Fx::audits('access.staff_user.cancelled', $invited))->toBe(1);
    });

    it('cannot be brought back by enabling the closed account', function () {
        Fx::staff(superAdmin: true);
        $revoked = Fx::staff(superAdmin: true);
        app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin(emailOf($revoked)));
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_DISABLE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);

        // The account is closed, not disabled: the way back is a fresh invitation (amendment 45).
        expect(fn () => app(EnableStaffHandler::class)->handle(new EnableStaff($revoked, Fx::change($revoked, ['sa'], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE])))))
            ->toThrow(InvalidStaffStatus::class);
    });

    it('never revokes the last active Super Admin; an invited or disabled one does not count', function () {
        $last = Fx::staff(superAdmin: true);
        Fx::staff(StaffStatus::Invited, superAdmin: true);
        $disabled = Fx::staff(StaffStatus::Disabled);
        DB::table('access.staff_users')->where('id', $disabled)->update(['is_super_admin' => true]);

        expect(fn () => app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin(emailOf($last))))->toThrow(LastSuperAdmin::class);

        app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin(emailOf($disabled)));
        expect(staffByEmail(emailOf($disabled))['is_super_admin'])->toBeFalse();
    });

    it('refuses someone who is not a Super Admin', function () {
        expect(fn () => app(RevokeSuperAdminHandler::class)->handle(new RevokeSuperAdmin(emailOf(Fx::staff()))))->toThrow(InvalidAccessAttribute::class);
    });
});

describe('the console commands', function () {
    it('creates a Super Admin from the command line', function () {
        superAdminConsole('access:super-admin:create', [
            'email' => 'owner@example.test', 'first_name' => 'Abood', 'last_name' => 'Owner', '--job-title' => 'Founder',
            '--date-of-birth' => '1995-01-01', '--country' => 'SA', '--phone' => '+966 50 111 2233', '--locale' => 'en',
        ])->expectsOutputToContain('Super Admin created')->assertSuccessful();

        expect(staffByEmail('owner@example.test')['phone'])->toBe('+966501112233')
            ->and(staffByEmail('owner@example.test')['locale'])->toBe('en');
    });

    it('promotes with only the email, and says the profile values were ignored', function () {
        $staffId = Fx::staff(firstName: 'Sara');

        superAdminConsole('access:super-admin:create', ['email' => emailOf($staffId)])
            ->expectsOutputToContain('now a Super Admin')->doesntExpectOutputToContain('ignored')->assertSuccessful();

        superAdminConsole('access:super-admin:create', ['email' => emailOf($staffId), 'first_name' => 'Other'])
            ->expectsOutputToContain('profile values were ignored')->assertSuccessful();

        expect(staffByEmail(emailOf($staffId))['first_name'])->toBe('Sara');
    });

    it('prints the error and fails, changing nothing', function () {
        superAdminConsole('access:super-admin:create', ['email' => 'owner@example.test', 'first_name' => 'Abood'])
            ->expectsOutputToContain('Invalid')->assertFailed();

        $last = Fx::staff(superAdmin: true);
        superAdminConsole('access:super-admin:revoke', ['email' => emailOf($last)])->expectsOutputToContain('last active Super Admin')->assertFailed();
        superAdminConsole('access:super-admin:reset-phone', ['email' => 'nobody@example.test'])->expectsOutputToContain('No staff member')->assertFailed();

        expect(staffByEmail('owner@example.test'))->toBe([]);
    });

    it('resets a Super Admin\'s lost phone, and only a Super Admin\'s', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        $staff = Fx::staff();

        superAdminConsole('access:super-admin:reset-phone', ['email' => emailOf($superAdmin)])->assertSuccessful();
        superAdminConsole('access:super-admin:reset-phone', ['email' => emailOf($staff)])->expectsOutputToContain('not a Super Admin')->assertFailed();

        expect(staffByEmail(emailOf($superAdmin))['phone'])->toBeNull()
            ->and(staffByEmail(emailOf($superAdmin))['phone_verified_at'])->toBeNull()
            ->and(staffByEmail(emailOf($superAdmin))['status'])->toBe('ACTIVE')
            ->and(staffByEmail(emailOf($staff))['phone'])->not->toBeNull()
            ->and(Fx::audits('access.staff_user.phone_reset', $superAdmin))->toBe(1);
    });

    it('revokes from the command line', function () {
        Fx::staff(superAdmin: true);
        $revoked = Fx::staff(superAdmin: true);

        superAdminConsole('access:super-admin:revoke', ['email' => emailOf($revoked)])->expectsOutputToContain('disabled until an admin enables it together with a role')->assertSuccessful();
    });

    it('needs the communication language for a new account', function () {
        superAdminConsole('access:super-admin:create', [
            'email' => 'owner@example.test', 'first_name' => 'Abood', 'last_name' => 'Owner', '--job-title' => 'Founder',
            '--date-of-birth' => '1995-01-01', '--country' => 'SA', '--phone' => '+966501112233',
        ])->expectsOutputToContain('Invalid')->assertFailed();

        expect(staffByEmail('owner@example.test'))->toBe([]);
    });
});

describe('what a promotion leaves behind (review of step 3a)', function () {
    it('gives every permission at once, and ends an email change someone else asked for', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::warmCache($staffId);
        DB::table('access.staff_email_changes')->insert([
            'staff_user_id' => $staffId, 'new_email' => 'admin.own@example.test', 'token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addDay(), 'requested_by' => null, 'created_at' => now(),
        ]);

        app(CreateSuperAdminHandler::class)->handle(new CreateSuperAdmin(emailOf($staffId)));

        Fx::actAsStaff($staffId);
        expect(Fx::allows(PlatformPermissions::SETTINGS_UPDATE, Fx::inStore('eg')))->toBeTrue()
            ->and(DB::table('access.staff_email_changes')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(Fx::audits('access.staff_user.super_admin_granted', $staffId))->toBe(1);
    });

    it('sends a new Super Admin a link that works end to end', function () {
        FakeBreachList::install();
        app(CreateSuperAdminHandler::class)->handle(newSuperAdmin());
        $token = superAdminMessages()->lastInvitationToken();
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        app(AcceptStaffInvitationHandler::class)->handle(new AcceptStaffInvitation($token, 'a long enough password', '+966501112233'));
        app(ConfirmStaffInvitationHandler::class)->handle(new ConfirmStaffInvitation($token, superAdminMessages()->lastCode()));

        expect(staffByEmail('owner@example.test')['status'])->toBe('ACTIVE')
            ->and(staffByEmail('owner@example.test')['is_super_admin'])->toBeTrue();
    });
});
