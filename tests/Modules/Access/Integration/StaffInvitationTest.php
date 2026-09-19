<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitation;
use Modules\Access\Application\Command\AcceptStaffInvitation\AcceptStaffInvitationHandler;
use Modules\Access\Application\Command\CancelStaffInvitation\CancelStaffInvitation;
use Modules\Access\Application\Command\CancelStaffInvitation\CancelStaffInvitationHandler;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmail;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmailHandler;
use Modules\Access\Application\Command\ConfirmStaffInvitation\ConfirmStaffInvitation;
use Modules\Access\Application\Command\ConfirmStaffInvitation\ConfirmStaffInvitationHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\ResendStaffInvitation\ResendStaffInvitation;
use Modules\Access\Application\Command\ResendStaffInvitation\ResendStaffInvitationHandler;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfile;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfileHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\CodeRequestTooSoon;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\PasswordTooWeak;
use Modules\Access\Domain\Exception\PermissionEscalation;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Events\StaffActivated;
use Modules\Access\Public\Events\StaffDisabled;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

const INVITING_ADMIN = [AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE];

/**
 * @param  list<string>  $stores  store codes
 */
function invite(string $email = 'noura@example.test', string $phone = '+966501111111', array $stores = ['sa'], ?string $roleId = null, string $locale = 'en'): string
{
    return app(InviteStaffHandler::class)->handle(new InviteStaff(
        $email, 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, $phone, $locale,
        AccessLevel::SelectedStores, array_map(Fx::storeId(...), $stores),
        savedRoleId: $roleId ?? Fx::role([PlatformPermissions::STORE_UPDATE]),
    ));
}

/**
 * The messages a person would have received in this test.
 */
function sent(): RecordingSecurityMessages
{
    return RecordingSecurityMessages::installed();
}

function asGuest(): void
{
    Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
}

function accept(string $token, string $password = 'a long enough password', string $phone = '+966501111111'): void
{
    app(AcceptStaffInvitationHandler::class)->handle(new AcceptStaffInvitation($token, $password, $phone));
}

function confirmCode(string $token, string $code): void
{
    app(ConfirmStaffInvitationHandler::class)->handle(new ConfirmStaffInvitation($token, $code));
}

/**
 * @return array<string, mixed>
 */
function staffRow(string $staffId): array
{
    return (array) (DB::table('access.staff_users')->where('id', $staffId)->first() ?? throw new LogicException('no staff row'));
}

describe('inviting', function () {
    it('creates an invited staff member with the whole profile, their role, and emails the link in their language', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $roleId = Fx::role([PlatformPermissions::STORE_UPDATE]);

        $staffId = invite(roleId: $roleId, locale: 'ar');
        $row = staffRow($staffId);

        expect($row['status'])->toBe('INVITED')
            ->and($row['password'])->toBeNull()
            ->and($row['job_title'])->toBe('Store keeper')
            ->and($row['phone'])->toBe('+966501111111')
            ->and($row['phone_verified_at'])->toBeNull()
            ->and(Fx::roleOf($staffId))->toBe($roleId)
            ->and(sent()->invitations)->toHaveCount(1)
            ->and(sent()->invitations[0]['to'])->toBe('noura@example.test')
            ->and(sent()->invitations[0]['locale'])->toBe('ar')
            ->and(sent()->invitations[0]['link'])->toContain('/admin/invitation/')
            // Only the hash is stored.
            ->and(DB::table('access.staff_invitations')->where('staff_user_id', $staffId)->value('token_hash'))->toBe(hash('sha256', sent()->lastInvitationToken()))
            ->and(Fx::audits('access.staff_user.invited', $staffId))->toBe(1)
            ->and(DB::table('platform.audit_entries')->where('subject_id', $staffId)->where('action', 'access.staff_user.invited')->value('changes'))->not->toContain('Noura');
    });

    it('starts every notification in the panel and none by email', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();

        expect(DB::table('access.staff_notification_preferences')->where('staff_user_id', $staffId)->count())->toBe(4)
            ->and(DB::table('access.staff_notification_preferences')->where('staff_user_id', $staffId)->where('email', true)->exists())->toBeFalse()
            ->and(DB::table('access.staff_notification_preferences')->where('staff_user_id', $staffId)->where('panel', false)->exists())->toBeFalse();
    });

    it('refuses an email or a phone another staff member uses, before the database', function (Closure $invite, string $error) {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        invite('taken@example.test', '+966502222222');
        Fx::withoutStaffUniqueIndexes();

        expect($invite)->toThrow($error);
    })->with([
        'the email, in other letters' => [fn () => invite('TAKEN@example.test', '+966503333333'), StaffEmailInUse::class],
        'the phone' => [fn () => invite('other@example.test', '+966 50 222 2222'), PhoneAlreadyInUse::class],
    ]);

    it('needs both "invite staff" and "assign roles" in every store the new member gets', function (array $actions, array $stores) {
        Fx::actAsAdmin(['sa'], Fx::names($actions));

        expect(fn () => invite(stores: Fx::names($stores)))->toThrow(Unauthorized::class)
            ->and(DB::table('access.staff_users')->where('email', 'noura@example.test')->exists())->toBeFalse();
    })->with([
        'without assign roles' => [[AccessPermissions::STAFF_INVITE, PlatformPermissions::STORE_UPDATE], ['sa']],
        'without invite staff' => [[AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE], ['sa']],
        'a store outside theirs' => [INVITING_ADMIN, ['sa', 'ae']],
    ]);

    it('gives the new member only what the inviter holds, and an admin role only from a Super Admin', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);

        expect(fn () => invite(roleId: Fx::role([PlatformPermissions::SETTINGS_UPDATE])))->toThrow(PermissionEscalation::class)
            ->and(fn () => invite('admin@example.test', '+966504444444', roleId: Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin)))->toThrow(SuperAdminOnly::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $adminId = invite('admin@example.test', '+966504444444', roleId: Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin));

        expect(staffRow($adminId)['status'])->toBe('INVITED');
    });
});

describe('accepting', function () {
    it('takes a password and the phone, sends a code, and activates the account when the code is right', function () {
        Event::fake([StaffActivated::class]);
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();
        $token = sent()->lastInvitationToken();
        asGuest();

        accept($token);

        expect(staffRow($staffId)['status'])->toBe('INVITED')
            // The password waits, hashed, on the invitation until the code is right.
            ->and(staffRow($staffId)['password'])->toBeNull()
            ->and(sent()->codes)->toHaveCount(1)
            ->and(sent()->codes[0]['phone'])->toBe('+966501111111')
            ->and(sent()->codes[0]['locale'])->toBe('en')
            ->and(DB::table('access.staff_phone_codes')->where('staff_user_id', $staffId)->value('code_hash'))->not->toBe(sent()->lastCode());

        confirmCode($token, sent()->lastCode());
        $row = staffRow($staffId);

        expect($row['status'])->toBe('ACTIVE')
            ->and(Hash::check('a long enough password', (string) $row['password']))->toBeTrue()
            ->and($row['phone_verified_at'])->not->toBeNull()
            ->and(DB::table('access.staff_invitations')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(DB::table('access.staff_phone_codes')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(Fx::audits('access.staff_user.accepted', $staffId))->toBe(1);
        Event::assertDispatched(StaffActivated::class, fn (StaffActivated $event): bool => $event->staffId === $staffId);
    });

    it('lets the invitee correct a phone the admin mistyped', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite(phone: '+966501111111');
        $token = sent()->lastInvitationToken();
        asGuest();

        accept($token, phone: '+966507777777');
        confirmCode($token, sent()->lastCode());

        expect(staffRow($staffId)['phone'])->toBe('+966507777777');
    });

    it('refuses a short password, a leaked one, and a phone another staff member uses', function (Closure $accept, string $error) {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        Fx::staff();
        invite();
        $token = sent()->lastInvitationToken();
        asGuest();

        expect(fn () => $accept($token))->toThrow($error);
    })->with([
        'eleven characters' => [fn (string $token) => accept($token, 'elevenchars'), PasswordTooWeak::class],
        'found in a breach' => [fn (string $token) => accept($token, FakeBreachList::LEAKED), PasswordTooWeak::class],
        'a taken phone' => [function (string $token) {
            DB::table('access.staff_users')->where('id', Fx::staff())->update(['phone' => '+966508888888']);
            Fx::withoutStaffUniqueIndexes();
            accept($token, phone: '+966508888888');
        }, PhoneAlreadyInUse::class],
    ]);

    it('refuses the phone when someone else took it after the code was sent', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();
        $token = sent()->lastInvitationToken();
        asGuest();
        accept($token, phone: '+966508888888');
        Fx::withoutStaffUniqueIndexes();
        DB::table('access.staff_users')->where('id', Fx::staff())->update(['phone' => '+966508888888']);

        expect(fn () => confirmCode($token, sent()->lastCode()))->toThrow(PhoneAlreadyInUse::class)
            ->and(staffRow($staffId)['status'])->toBe('INVITED');
    });

    it('refuses an unknown, replaced or expired link', function (Closure $token) {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();
        $link = $token($staffId, sent());
        asGuest();

        expect(fn () => accept($link))->toThrow(InvalidOrExpiredLink::class);
    })->with([
        'unknown' => [fn () => 'not-a-real-token'],
        'replaced by a resend' => [function (string $staffId, RecordingSecurityMessages $messages) {
            $first = $messages->lastInvitationToken();
            app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($staffId));

            return $first;
        }],
        'expired' => [function (string $staffId, RecordingSecurityMessages $messages) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(73));

            return $messages->lastInvitationToken();
        }],
    ]);

    it('counts wrong codes even though the request fails, and kills the code after five', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();
        $token = sent()->lastInvitationToken();
        asGuest();
        accept($token);
        $right = sent()->lastCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $try) {
            expect(fn () => confirmCode($token, $wrong))->toThrow(InvalidCode::class);
        }

        expect(DB::table('access.staff_phone_codes')->where('staff_user_id', $staffId)->value('attempts'))->toBe(4);

        expect(fn () => confirmCode($token, $wrong))->toThrow(InvalidCode::class, 'request a new one')
            // Dead: even the right code no longer works.
            ->and(fn () => confirmCode($token, $right))->toThrow(InvalidCode::class)
            ->and(staffRow($staffId)['status'])->toBe('INVITED');
    });

    it('refuses even the right code once the invitation itself has expired', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();
        $token = sent()->lastInvitationToken();
        asGuest();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(72)->subMinute());
        accept($token);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        expect(fn () => confirmCode($token, sent()->lastCode()))->toThrow(InvalidOrExpiredLink::class)
            ->and(staffRow($staffId)['status'])->toBe('INVITED');
    });

    it('refuses an expired code', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        invite();
        $token = sent()->lastInvitationToken();
        asGuest();
        accept($token);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));

        expect(fn () => confirmCode($token, sent()->lastCode()))->toThrow(InvalidCode::class);
    });

    it('sends a new code no sooner than a minute later, and at most three an hour to one number', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        invite();
        $token = sent()->lastInvitationToken();
        asGuest();
        accept($token);

        expect(fn () => accept($token))->toThrow(CodeRequestTooSoon::class);

        foreach (range(2, 3) as $sent) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            accept($token);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        expect(sent()->codes)->toHaveCount(3)
            ->and(fn () => accept($token))->toThrow(CodeRequestTooSoon::class);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        accept($token);

        expect(sent()->codes)->toHaveCount(4);
    });

    it('counts the hourly limit per number, whoever asks', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        invite('first@example.test', '+966501111111');
        $first = sent()->lastInvitationToken();
        invite('second@example.test', '+966502222222');
        $second = sent()->lastInvitationToken();
        asGuest();

        accept($first, phone: '+966507777777');
        accept($second, phone: '+966507777777');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        accept($first, phone: '+966507777777');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        expect(fn () => accept($second, phone: '+966507777777'))->toThrow(CodeRequestTooSoon::class)
            ->and(sent()->codes)->toHaveCount(3);
    });

    it('follows the security settings, not fixed numbers', function (string $key, int $value, Closure $check) {
        Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, null, $value)));
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        invite();
        asGuest();

        $check(sent()->lastInvitationToken());
    })->with([
        'a 16-character minimum password' => [StaffSecuritySettings::PASSWORD_MIN_LENGTH, 16, function (string $token): void {
            expect(fn () => accept($token, str_repeat('a', 15)))->toThrow(PasswordTooWeak::class);
            accept($token, str_repeat('a', 16));
        }],
        '8-digit codes' => [StaffSecuritySettings::CODE_LENGTH, 8, function (string $token): void {
            accept($token);
            expect(sent()->lastCode())->toMatch('/^\d{8}$/');
        }],
        'a 1-hour invitation' => [StaffSecuritySettings::INVITATION_HOURS, 1, function (string $token): void {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(61));
            expect(fn () => accept($token))->toThrow(InvalidOrExpiredLink::class);
        }],
        'one code an hour' => [StaffSecuritySettings::CODES_PER_HOUR, 1, function (string $token): void {
            accept($token);
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
            expect(fn () => accept($token))->toThrow(CodeRequestTooSoon::class);
        }],
    ]);

    it('counts characters, not bytes: eleven Arabic letters are too short, twelve are enough', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        invite();
        $token = sent()->lastInvitationToken();
        asGuest();

        // Eleven Arabic letters are 22 bytes.
        expect(fn () => accept($token, str_repeat('ب', 11)))->toThrow(PasswordTooWeak::class);

        accept($token, str_repeat('ب', 12));
        expect(sent()->codes)->toHaveCount(1);
    });

    it('is only for someone not signed in as staff', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        invite();

        expect(fn () => accept(sent()->lastInvitationToken()))->toThrow(Unauthorized::class);
    });
});

describe('resending and cancelling', function () {
    it('resends only while invited, to the staff an admin manages', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();

        app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($staffId));

        expect(sent()->invitations)->toHaveCount(2)
            ->and(Fx::audits('access.staff_user.invitation_resent', $staffId))->toBe(1);

        $active = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);

        expect(fn () => app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($active)))->toThrow(InvalidStaffStatus::class);
    });

    it('cancels an invitation: the link dies and the person stays invited, for a new link later', function () {
        Event::fake([StaffDisabled::class]);
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();
        $token = sent()->lastInvitationToken();

        app(CancelStaffInvitationHandler::class)->handle(new CancelStaffInvitation($staffId));
        asGuest();

        expect(staffRow($staffId)['status'])->toBe('INVITED')
            ->and(DB::table('access.staff_invitations')->where('staff_user_id', $staffId)->exists())->toBeFalse()
            ->and(fn () => accept($token))->toThrow(InvalidOrExpiredLink::class)
            ->and(Fx::audits('access.staff_user.invitation_cancelled', $staffId))->toBe(1);
        Event::assertNotDispatched(StaffDisabled::class);
    });

    it('refuses to resend or cancel for someone outside the admin\'s stores', function () {
        Fx::actAsAdmin(['sa', 'ae'], INVITING_ADMIN);
        $staffId = invite(stores: ['ae']);
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);

        expect(fn () => app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($staffId)))->toThrow(Unauthorized::class)
            ->and(fn () => app(CancelStaffInvitationHandler::class)->handle(new CancelStaffInvitation($staffId)))->toThrow(Unauthorized::class);
    });

    it('cancels only an invitation: someone active is disabled only with "disable staff"', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $active = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);

        expect(fn () => app(CancelStaffInvitationHandler::class)->handle(new CancelStaffInvitation($active)))->toThrow(InvalidStaffStatus::class)
            ->and(staffRow($active)['status'])->toBe('ACTIVE');
    });
});

describe('every new link (review of step 3a)', function () {
    it('works end to end whenever a new invitation replaces the old one', function (Closure $replace) {
        Fx::actAsAdmin(['sa'], [...INVITING_ADMIN, AccessPermissions::STAFF_DISABLE]);
        $staffId = invite();
        $replace($staffId);
        $token = sent()->lastInvitationToken();
        asGuest();

        accept($token);
        confirmCode($token, sent()->lastCode());

        expect(staffRow($staffId)['status'])->toBe('ACTIVE')
            ->and(sent()->invitations)->toHaveCount(2);
    })->with([
        'resent' => fn (string $id) => app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($id)),
        'cancelled, then resent' => function (string $id): void {
            app(CancelStaffInvitationHandler::class)->handle(new CancelStaffInvitation($id));
            app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($id));
        },
    ]);

    it('kills the code sent for the old link when resending', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();
        $token = sent()->lastInvitationToken();
        asGuest();
        accept($token);

        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($staffId));

        expect(DB::table('access.staff_phone_codes')->where('staff_user_id', $staffId)->exists())->toBeFalse();
    });

    it('activates the account\'s permissions at once when the code is right', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();
        $token = sent()->lastInvitationToken();
        Fx::warmCache($staffId);
        asGuest();

        accept($token);
        confirmCode($token, sent()->lastCode());
        Fx::actAsStaff($staffId);

        expect(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa')))->toBeTrue();
    });

    it('moves an invitation to a corrected address at once: the old link dies, the new one works', function () {
        Fx::actAsAdmin(['sa'], [...INVITING_ADMIN, AccessPermissions::STAFF_UPDATE]);
        $staffId = invite('mistyped@example.test');
        $old = sent()->lastInvitationToken();

        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'noura@example.test'));
        asGuest();

        expect(staffRow($staffId)['email'])->toBe('noura@example.test')
            ->and(sent()->emailChanges)->toBe([])
            ->and(sent()->invitations)->toHaveCount(2)
            ->and(sent()->invitations[1]['to'])->toBe('noura@example.test')
            ->and(fn () => accept($old))->toThrow(InvalidOrExpiredLink::class)
            ->and(Fx::audits('access.staff_user.email_changed', $staffId))->toBe(1);

        $new = sent()->lastInvitationToken();
        accept($new);
        confirmCode($new, sent()->lastCode());

        expect(staffRow($staffId)['status'])->toBe('ACTIVE');
    });

    it('lets only an admin holding every action of the person\'s role redirect their account', function (Closure $redirect) {
        // Invited by the console, with an action the admin below does not hold.
        $staffId = (string) Fx::asSystem(fn (): string => invite(roleId: Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::SETTINGS_UPDATE])));
        $row = staffRow($staffId);
        Fx::actAsAdmin(['sa'], [...INVITING_ADMIN, AccessPermissions::STAFF_UPDATE]);

        expect(fn () => $redirect($staffId))->toThrow(PermissionEscalation::class)
            ->and(staffRow($staffId))->toBe($row)
            ->and(sent()->invitations)->toHaveCount(1);
    })->with([
        'a new email' => fn (string $id) => app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($id, 'admin.own@example.test')),
        'a new phone' => fn (string $id) => app(UpdateStaffProfileHandler::class)->handle(new UpdateStaffProfile($id, 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, '+966509999999', null)),
        'a new invitation link' => fn (string $id) => app(ResendStaffInvitationHandler::class)->handle(new ResendStaffInvitation($id)),
    ]);

    it('still lets that admin edit the rest of the profile, keeping the phone', function () {
        $staffId = (string) Fx::asSystem(fn (): string => invite(roleId: Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::SETTINGS_UPDATE])));
        Fx::actAsAdmin(['sa'], [...INVITING_ADMIN, AccessPermissions::STAFF_UPDATE]);

        app(UpdateStaffProfileHandler::class)->handle(new UpdateStaffProfile($staffId, 'Noura', 'Saleh', 'Head of store', '1995-03-10', 'SA', null, '+966501111111', null));

        expect(staffRow($staffId)['job_title'])->toBe('Head of store');
    });

    it('refuses a dead link before asking the outside service about the password', function () {
        asGuest();

        expect(fn () => accept('not-a-real-token', FakeBreachList::LEAKED))->toThrow(InvalidOrExpiredLink::class);
    });

    it('never takes a code sent for another purpose', function () {
        Fx::actAsAdmin(['sa'], INVITING_ADMIN);
        $staffId = invite();
        $token = sent()->lastInvitationToken();
        asGuest();
        accept($token);
        DB::table('access.staff_phone_codes')->where('staff_user_id', $staffId)->update(['purpose' => 'CHANGE']);

        expect(fn () => confirmCode($token, sent()->lastCode()))->toThrow(InvalidCode::class)
            ->and(staffRow($staffId)['status'])->toBe('INVITED');
    });
});
