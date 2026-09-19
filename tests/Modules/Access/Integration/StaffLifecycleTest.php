<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitation;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitationHandler;
use Modules\Access\Application\Command\CancelExpiredSuperAdminInvitations\CancelExpiredSuperAdminInvitations;
use Modules\Access\Application\Command\CancelExpiredSuperAdminInvitations\CancelExpiredSuperAdminInvitationsHandler;
use Modules\Access\Application\Command\CancelStaffAccount\CancelStaffAccount;
use Modules\Access\Application\Command\CancelStaffAccount\CancelStaffAccountHandler;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmail;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmailHandler;
use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRoleHandler;
use Modules\Access\Application\Command\ChangeStaffRole\PersonalRole;
use Modules\Access\Application\Command\ConfirmStaffInvitation\ConfirmStaffInvitation;
use Modules\Access\Application\Command\ConfirmStaffInvitation\ConfirmStaffInvitationHandler;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdmin;
use Modules\Access\Application\Command\CreateSuperAdmin\CreateSuperAdminHandler;
use Modules\Access\Application\Command\EnableStaff\EnableStaff;
use Modules\Access\Application\Command\EnableStaff\EnableStaffHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\ResendStaffInvitation\ResendStaffInvitation;
use Modules\Access\Application\Command\ResendStaffInvitation\ResendStaffInvitationHandler;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfile;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfileHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\StaffNotEditable;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Infrastructure\Queue\CancelExpiredSuperAdminInvitationsJob;
use Modules\Access\Public\Enums\AccessLevel;
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

const LIFECYCLE_ADMIN = [AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_ASSIGN_ROLE, AccessPermissions::STAFF_UPDATE, AccessPermissions::STAFF_DISABLE, PlatformPermissions::STORE_UPDATE];

function lifecycleInvite(string $email = 'noura@example.test', string $phone = '+966501111111'): string
{
    return app(InviteStaffHandler::class)->handle(new InviteStaff(
        $email, 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, $phone, 'en',
        AccessLevel::SelectedStores, [Fx::storeId('sa')], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]),
    ));
}

function lifecycleStatus(string $staffId): string
{
    return (string) DB::table('access.staff_users')->where('id', $staffId)->value('status');
}

function lifecycleSuperAdmin(string $email = 'owner@example.test', string $phone = '+966501112233'): string
{
    app(CreateSuperAdminHandler::class)->handle(new CreateSuperAdmin($email, 'Abood', 'Owner', 'Founder', '1995-01-01', 'SA', $phone, 'ar'));

    return (string) DB::table('access.staff_users')->where('email', $email)->where('status', 'INVITED')->value('id');
}

/**
 * @param  array<string, string>  $parameters
 */
function lifecycleConsole(string $command, array $parameters): PendingCommand
{
    $pending = artisan($command, $parameters);

    return $pending instanceof PendingCommand ? $pending : throw new LogicException('Expected a pending command');
}

describe('cancelling an account (amendment 29)', function () {
    it('cancels an invited person for good and frees their email and phone for a new invitation', function () {
        Fx::actAsAdmin(['sa'], LIFECYCLE_ADMIN);
        $staffId = lifecycleInvite();
        $token = RecordingSecurityMessages::installed()->lastInvitationToken();

        app(CancelStaffAccountHandler::class)->handle(new CancelStaffAccount($staffId));

        expect(lifecycleStatus($staffId))->toBe('CANCELLED')
            ->and(Fx::roleOf($staffId))->toBe('')
            ->and(DB::table('access.staff_invitations')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(Fx::audits('access.staff_user.cancelled', $staffId))->toBe(1);

        $again = lifecycleInvite();

        expect($again)->not->toBe($staffId)
            ->and(lifecycleStatus($again))->toBe('INVITED');

        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
        expect(fn () => app(AcceptStaffInvitationHandler::class)->handle(new AcceptStaffInvitation($token, 'a long enough password', '+966501111111')))
            ->toThrow(InvalidOrExpiredLink::class);
    });

    it('removes a personal role made for the invitation', function () {
        Fx::actAsAdmin(['sa'], LIFECYCLE_ADMIN);
        $staffId = app(InviteStaffHandler::class)->handle(new InviteStaff(
            'noura@example.test', 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966501111111', 'en',
            AccessLevel::SelectedStores, [Fx::storeId('sa')], personalRole: new PersonalRole('دور نورة', 'Noura\'s role', [PlatformPermissions::STORE_UPDATE]),
        ));

        expect(DB::table('access.roles')->where('kind', 'PERSONAL')->count())->toBe(1);

        app(CancelStaffAccountHandler::class)->handle(new CancelStaffAccount($staffId));

        expect(DB::table('access.roles')->where('kind', 'PERSONAL')->exists())->toBeFalse()
            ->and(Fx::roleOf($staffId))->toBe('');
    });

    it('is only for the admin who invited them, while they may still invite, or a Super Admin', function (Closure $canceller, ?string $error) {
        $inviterId = Fx::actAsAdmin(['sa'], LIFECYCLE_ADMIN);
        $staffId = lifecycleInvite();
        $canceller($inviterId);

        $cancel = fn () => app(CancelStaffAccountHandler::class)->handle(new CancelStaffAccount($staffId));

        if ($error === null) {
            $cancel();
            expect(lifecycleStatus($staffId))->toBe('CANCELLED');
        } else {
            expect($cancel)->toThrow($error)
                ->and(lifecycleStatus($staffId))->toBe('INVITED');
        }
    })->with([
        'another admin with the same rights' => [fn () => Fx::actAsAdmin(['sa'], LIFECYCLE_ADMIN), Unauthorized::class],
        'the inviter, no longer allowed to invite' => [function (string $inviterId): void {
            Fx::assign($inviterId, Fx::role([AccessPermissions::STAFF_UPDATE, PlatformPermissions::STORE_UPDATE], RoleLevel::Admin), ['sa']);
            Fx::actAsStaff($inviterId);
        }, Unauthorized::class],
        'a Super Admin' => [fn () => Fx::actAsStaff(Fx::staff(superAdmin: true)), null],
    ]);

    it('cancels only someone who has not accepted', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(CancelStaffAccountHandler::class)->handle(new CancelStaffAccount($staffId)))->toThrow(InvalidStaffStatus::class)
            ->and(lifecycleStatus($staffId))->toBe('ACTIVE');
    });

    it('never cancels a Super Admin\'s invitation in the panel', function () {
        Fx::staff(superAdmin: true);
        $invited = lifecycleSuperAdmin();
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => app(CancelStaffAccountHandler::class)->handle(new CancelStaffAccount($invited)))->toThrow(StaffNotEditable::class);
    });

    it('keeps a cancelled account final', function (Closure $change) {
        $adminId = Fx::actAsAdmin(['sa'], LIFECYCLE_ADMIN);
        $staffId = lifecycleInvite();
        app(CancelStaffAccountHandler::class)->handle(new CancelStaffAccount($staffId));
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        expect(fn () => $change($staffId))->toThrow(InvalidStaffStatus::class)
            ->and(lifecycleStatus($staffId))->toBe('CANCELLED');
    })->with([
        'resend' => fn (string $id) => app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($id)),
        'enable' => fn (string $id) => app(EnableStaffHandler::class)->handle(new EnableStaff($id, Fx::change($id, ['sa'], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE])))),
        'give a role' => fn (string $id) => app(ChangeStaffRoleHandler::class)->handle(Fx::change($id, ['sa'], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]))),
        'edit the profile' => fn (string $id) => app(UpdateStaffProfileHandler::class)->handle(new UpdateStaffProfile($id, 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966501111111', null)),
        'change the email' => fn (string $id) => app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($id, 'noura.new@example.test')),
    ]);
});

describe('Super Admin invitations (amendment 30)', function () {
    it('works 24 hours, a staff invitation 72', function () {
        Fx::staff(superAdmin: true);
        $superAdmin = lifecycleSuperAdmin();
        Fx::actAsAdmin(['sa'], LIFECYCLE_ADMIN);
        $staff = lifecycleInvite();

        $expires = fn (string $id): CarbonImmutable => CarbonImmutable::parse((string) DB::table('access.staff_invitations')->where('staff_user_id', $id)->value('expires_at'));

        expect((int) round(CarbonImmutable::now()->diffInMinutes($expires($superAdmin))))->toBe(24 * 60)
            ->and((int) round(CarbonImmutable::now()->diffInMinutes($expires($staff))))->toBe(72 * 60);
    });

    it('is resent and cancelled from the console only for an invited Super Admin', function () {
        $invited = lifecycleSuperAdmin();
        $first = RecordingSecurityMessages::installed()->lastInvitationToken();

        lifecycleConsole('access:super-admin:resend-invitation', ['email' => 'owner@example.test'])->assertSuccessful();

        expect(RecordingSecurityMessages::installed()->lastInvitationToken())->not->toBe($first);

        lifecycleConsole('access:super-admin:cancel', ['email' => 'owner@example.test'])->expectsOutputToContain('can be used for a new invitation')->assertSuccessful();

        expect(lifecycleStatus($invited))->toBe('CANCELLED');

        $active = Fx::staff(superAdmin: true);
        $email = (string) DB::table('access.staff_users')->where('id', $active)->value('email');

        lifecycleConsole('access:super-admin:resend-invitation', ['email' => $email])->assertFailed();
        lifecycleConsole('access:super-admin:cancel', ['email' => $email])->assertFailed();
        lifecycleConsole('access:super-admin:cancel', ['email' => (string) DB::table('access.staff_users')->where('id', Fx::staff())->value('email')])->expectsOutputToContain('not a Super Admin')->assertFailed();
    });

    it('frees an email a cancelled invitation held, for a new Super Admin', function () {
        $cancelled = lifecycleSuperAdmin();
        lifecycleConsole('access:super-admin:cancel', ['email' => 'owner@example.test'])->assertSuccessful();

        $again = lifecycleSuperAdmin();

        expect($again)->not->toBe($cancelled)
            ->and(lifecycleStatus($cancelled))->toBe('CANCELLED')
            ->and(lifecycleStatus($again))->toBe('INVITED');
    });

    it('cancels Super Admin invitations left unaccepted 24 hours, and only those', function () {
        $expired = lifecycleSuperAdmin('expired@example.test', '+966501000001');
        $resent = lifecycleSuperAdmin('resent@example.test', '+966501000002');
        $accepted = lifecycleSuperAdmin('accepted@example.test', '+966501000003');
        $acceptedToken = RecordingSecurityMessages::installed()->lastInvitationToken();
        Fx::actAsAdmin(['sa'], LIFECYCLE_ADMIN);
        $staff = lifecycleInvite();

        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
        app(AcceptStaffInvitationHandler::class)->handle(new AcceptStaffInvitation($acceptedToken, 'a long enough password', '+966501000003'));
        app(ConfirmStaffInvitationHandler::class)->handle(new ConfirmStaffInvitation($acceptedToken, RecordingSecurityMessages::installed()->lastCode()));
        Fx::actAs(Actor::system());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(23));
        lifecycleConsole('access:super-admin:resend-invitation', ['email' => 'resent@example.test'])->assertSuccessful();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(2));

        $cancelled = app(CancelExpiredSuperAdminInvitationsHandler::class)->handle(new CancelExpiredSuperAdminInvitations);

        expect($cancelled)->toBe(1)
            ->and(lifecycleStatus($expired))->toBe('CANCELLED')
            ->and(lifecycleStatus($resent))->toBe('INVITED')
            ->and(lifecycleStatus($accepted))->toBe('ACTIVE')
            ->and(lifecycleStatus($staff))->toBe('INVITED');
    });

    it('counts an invited Super Admin with no invitation left as expired: nothing can be accepted', function () {
        $noLink = lifecycleSuperAdmin();
        DB::table('access.staff_invitations')->where('staff_user_id', $noLink)->delete();

        expect(app(CancelExpiredSuperAdminInvitationsHandler::class)->handle(new CancelExpiredSuperAdminInvitations))->toBe(1)
            ->and(lifecycleStatus($noLink))->toBe('CANCELLED');
    });

    it('checks again under the lock, so an invitation resent meanwhile is kept', function () {
        $invited = lifecycleSuperAdmin();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(25));

        // The list is read; then the console resends before the sweep takes the lock.
        $real = app(StaffUserRepository::class);
        $lists = 0;
        $staff = Mockery::mock(StaffUserRepository::class);
        $staff->shouldReceive('byId')->andReturnUsing(fn (string $id) => $real->byId($id));
        $staff->shouldReceive('superAdminInvitationsSentBefore')->andReturnUsing(function (DateTimeImmutable $cutoff) use ($real, &$lists): array {
            $expired = $real->superAdminInvitationsSentBefore($cutoff);

            if ($lists++ === 0) {
                lifecycleConsole('access:super-admin:resend-invitation', ['email' => 'owner@example.test'])->assertSuccessful();
            }

            return $expired;
        });

        $cancelled = app()->make(CancelExpiredSuperAdminInvitationsHandler::class, ['staff' => $staff])->handle(new CancelExpiredSuperAdminInvitations);

        expect($cancelled)->toBe(0)
            ->and($lists)->toBe(2)
            ->and(lifecycleStatus($invited))->toBe('INVITED');
    });

    it('is swept by a queued job every ten minutes, from one server', function () {
        Queue::fake();
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event): bool => $event->description === CancelExpiredSuperAdminInvitationsJob::class);

        expect($events)->toHaveCount(1)
            ->and($events->first()?->expression)->toBe('*/10 * * * *')
            ->and($events->first()?->onOneServer)->toBeTrue()
            ->and(new CancelExpiredSuperAdminInvitationsJob)->toBeInstanceOf(ShouldBeUnique::class);

        $events->first()?->run(app());

        Queue::assertPushed(CancelExpiredSuperAdminInvitationsJob::class, 1);
    });
});
