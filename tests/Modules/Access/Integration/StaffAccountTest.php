<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmail;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmailHandler;
use Modules\Access\Application\Command\ConfirmStaffEmailChange\ConfirmStaffEmailChange;
use Modules\Access\Application\Command\ConfirmStaffEmailChange\ConfirmStaffEmailChangeHandler;
use Modules\Access\Application\Command\DisableStaff\DisableStaff;
use Modules\Access\Application\Command\DisableStaff\DisableStaffHandler;
use Modules\Access\Application\Command\EnableStaff\EnableStaff;
use Modules\Access\Application\Command\EnableStaff\EnableStaffHandler;
use Modules\Access\Application\Command\InviteStaff\InviteStaff;
use Modules\Access\Application\Command\InviteStaff\InviteStaffHandler;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChange;
use Modules\Access\Application\Command\RequestOwnPhoneChange\RequestOwnPhoneChangeHandler;
use Modules\Access\Application\Command\UpdateOwnNotificationPreferences\UpdateOwnNotificationPreferences;
use Modules\Access\Application\Command\UpdateOwnNotificationPreferences\UpdateOwnNotificationPreferencesHandler;
use Modules\Access\Application\Command\UpdateOwnStaffProfile\UpdateOwnStaffProfile;
use Modules\Access\Application\Command\UpdateOwnStaffProfile\UpdateOwnStaffProfileHandler;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfile;
use Modules\Access\Application\Command\UpdateStaffProfile\UpdateStaffProfileHandler;
use Modules\Access\Application\Command\VerifyOwnPhoneChange\VerifyOwnPhoneChange;
use Modules\Access\Application\Command\VerifyOwnPhoneChange\VerifyOwnPhoneChangeHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidCode;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Exception\StaffNotEditable;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Access\Public\Events\StaffActivated;
use Modules\Access\Public\Events\StaffDisabled;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    RecordingSecurityMessages::install();
});

const ACCOUNT_ADMIN = [AccessPermissions::STAFF_UPDATE, AccessPermissions::STAFF_DISABLE, PlatformPermissions::STORE_UPDATE];

function received(): RecordingSecurityMessages
{
    return RecordingSecurityMessages::installed();
}

function column(string $staffId, string $column): mixed
{
    return DB::table('access.staff_users')->where('id', $staffId)->value($column);
}

/**
 * A public image in Platform's media table, as an upload leaves it.
 */
function publicImage(string $visibility = 'PUBLIC', string $mime = 'image/jpeg'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('platform.media')->insert([
        'id' => $id, 'visibility' => $visibility, 'disk' => 'local', 'object_key' => "media/{$id}.jpg",
        'original_filename' => 'face.jpg', 'mime' => $mime, 'bytes' => 1000, 'width' => 10, 'height' => 10,
        'checksum' => hash('sha256', $id), 'variants_status' => 'READY', 'variants_queued_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function profileUpdate(string $staffId, string $phone, ?string $avatar = null, string $lastName = 'Member'): UpdateStaffProfile
{
    return new UpdateStaffProfile($staffId, 'Staff', $lastName, 'Tester', '1990-01-01', 'SA', 'Riyadh', $phone, $avatar);
}

describe('disabling and enabling', function () {
    it('disables a staff member, who then holds nothing, and enables them again', function () {
        Event::fake([StaffDisabled::class, StaffActivated::class]);
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);

        app(DisableStaffHandler::class)->handle(new DisableStaff($staffId));

        expect(column($staffId, 'status'))->toBe('DISABLED');
        Event::assertDispatched(StaffDisabled::class);

        app(EnableStaffHandler::class)->handle(new EnableStaff($staffId));

        expect(column($staffId, 'status'))->toBe('ACTIVE')
            ->and(Fx::audits('access.staff_user.disabled', $staffId))->toBe(1)
            ->and(Fx::audits('access.staff_user.enabled', $staffId))->toBe(1);
        Event::assertDispatched(StaffActivated::class);
    });

    it('sends a new invitation when enabling someone who never accepted', function () {
        $staffId = Fx::staff(StaffStatus::Invited);
        Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE]), ['sa']);
        DB::table('access.staff_users')->where('id', $staffId)->update(['status' => 'DISABLED']);
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);

        app(EnableStaffHandler::class)->handle(new EnableStaff($staffId));

        expect(column($staffId, 'status'))->toBe('INVITED')
            ->and(received()->invitations)->toHaveCount(1);
    });
});

it('never lets an admin change a Super Admin, another admin or themselves, nor staff outside their stores', function (Closure $target, string $error, Closure $change) {
    $adminId = Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);
    $targetId = $target($adminId);
    $before = (array) DB::table('access.staff_users')->where('id', $targetId)->first();

    expect(fn () => $change($targetId))->toThrow($error)
        ->and((array) DB::table('access.staff_users')->where('id', $targetId)->first())->toBe($before)
        ->and(received()->emailChanges)->toBe([]);
})->with([
    'a Super Admin' => [fn () => Fx::staff(superAdmin: true), StaffNotEditable::class],
    'an admin' => [fn () => Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], RoleLevel::Admin), StaffNotEditable::class],
    'themselves' => [fn (string $adminId) => $adminId, StaffNotEditable::class],
    'outside their stores' => [fn () => Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa', 'ae']), Unauthorized::class],
])->with([
    'disable' => fn (string $id) => app(DisableStaffHandler::class)->handle(new DisableStaff($id)),
    'enable' => fn (string $id) => app(EnableStaffHandler::class)->handle(new EnableStaff($id)),
    'edit the profile' => fn (string $id) => app(UpdateStaffProfileHandler::class)->handle(profileUpdate($id, (string) column($id, 'phone'), lastName: 'Changed')),
    'change the email' => fn (string $id) => app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($id, 'changed@example.test')),
]);

describe('an admin editing a profile', function () {
    it('updates the profile, recording personal fields only as changed, and leaves a new phone unverified', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);

        app(UpdateStaffProfileHandler::class)->handle(profileUpdate($staffId, '+966509876543', lastName: 'Harbi'));

        $audit = (string) DB::table('platform.audit_entries')->where('subject_id', $staffId)->where('action', 'access.staff_user.profile_updated')->value('changes');

        expect(column($staffId, 'last_name'))->toBe('Harbi')
            ->and(column($staffId, 'phone'))->toBe('+966509876543')
            ->and(column($staffId, 'phone_verified_at'))->toBeNull()
            ->and(json_decode($audit, true))->toBe(['phone' => 'changed', 'address' => 'changed', 'last_name' => 'changed']);
        expect($audit)->not->toContain('Harbi');
        expect($audit)->not->toContain('966509876543');
    });

    it('refuses a phone another staff member uses, and an avatar that is not an uploaded public image', function (Closure $update, string $error) {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);
        Fx::withoutStaffUniqueIndexes();

        expect(fn () => app(UpdateStaffProfileHandler::class)->handle($update($staffId)))->toThrow($error);
    })->with([
        'a taken phone' => [fn (string $staffId) => profileUpdate($staffId, (string) column(Fx::staff(), 'phone')), PhoneAlreadyInUse::class],
        'unknown media' => [fn (string $staffId) => profileUpdate($staffId, (string) column($staffId, 'phone'), '01j8z3k4m5n6p7q8r9s0t1v2w3'), InvalidAccessAttribute::class],
        'a private file' => [fn (string $staffId) => profileUpdate($staffId, (string) column($staffId, 'phone'), publicImage('PRIVATE')), InvalidAccessAttribute::class],
    ]);

    it('sets and clears an avatar', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $image = publicImage();
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);

        app(UpdateStaffProfileHandler::class)->handle(profileUpdate($staffId, (string) column($staffId, 'phone'), $image));
        expect(column($staffId, 'avatar_media_id'))->toBe($image);

        app(UpdateStaffProfileHandler::class)->handle(profileUpdate($staffId, (string) column($staffId, 'phone')));
        expect(column($staffId, 'avatar_media_id'))->toBeNull();
    });
});

describe('changing a staff email', function () {
    it('takes effect only when the link sent to the new address is used', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $old = column($staffId, 'email');
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);

        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'new.address@example.test'));

        expect(column($staffId, 'email'))->toBe($old)
            ->and(received()->emailChanges)->toHaveCount(1)
            ->and(received()->emailChanges[0]['to'])->toBe('new.address@example.test');

        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
        app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange(received()->lastEmailChangeToken()));

        expect(column($staffId, 'email'))->toBe('new.address@example.test')
            ->and(fn () => app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange(received()->lastEmailChangeToken())))->toThrow(InvalidOrExpiredLink::class);
    });

    it('refuses an email another staff member uses, when entered and when the link is used', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $other = Fx::staff();
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);
        Fx::withoutStaffUniqueIndexes();

        expect(fn () => app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, strtoupper((string) column($other, 'email')))))->toThrow(StaffEmailInUse::class);

        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'wanted@example.test'));
        DB::table('access.staff_users')->where('id', $other)->update(['email' => 'wanted@example.test']);
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(fn () => app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange(received()->lastEmailChangeToken())))->toThrow(StaffEmailInUse::class);
    });

    it('refuses the link after 72 hours, keeping the current email', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $old = column($staffId, 'email');
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);
        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'new.address@example.test'));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(72)->addMinute());
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(fn () => app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange(received()->lastEmailChangeToken())))->toThrow(InvalidOrExpiredLink::class)
            ->and(column($staffId, 'email'))->toBe($old);
    });

    it('lets a Super Admin change their own, and nobody else\'s from the panel', function () {
        $superAdmin = Fx::staff(superAdmin: true);
        Fx::actAsStaff($superAdmin);

        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($superAdmin, 'me@example.test'));
        expect(received()->emailChanges)->toHaveCount(1);

        expect(fn () => app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail(Fx::staff(superAdmin: true), 'other@example.test')))->toThrow(StaffNotEditable::class);
    });
});

describe('a staff member\'s own account', function () {
    it('edits their profile and communication language', function () {
        $staffId = Fx::staff();
        Fx::actAsStaff($staffId);

        app(UpdateOwnStaffProfileHandler::class)->handle(new UpdateOwnStaffProfile('Staff', 'Member', 'Lead tester', '1990-01-01', 'EG', null, 'ar', null));

        expect(column($staffId, 'job_title'))->toBe('Lead tester')
            ->and(column($staffId, 'country'))->toBe('EG')
            ->and(column($staffId, 'locale'))->toBe('ar');
    });

    it('changes their phone only when the code sent to the new one is right, and keeps the old until then', function () {
        $staffId = Fx::staff();
        $old = column($staffId, 'phone');
        Fx::actAsStaff($staffId);

        app(RequestOwnPhoneChangeHandler::class)->handle(new RequestOwnPhoneChange('+966505550000'));

        expect(column($staffId, 'phone'))->toBe($old)
            ->and(received()->codes[0]['phone'])->toBe('+966505550000')
            ->and(fn () => app(VerifyOwnPhoneChangeHandler::class)->handle(new VerifyOwnPhoneChange('not the code')))->toThrow(InvalidCode::class);

        app(VerifyOwnPhoneChangeHandler::class)->handle(new VerifyOwnPhoneChange(received()->lastCode()));

        expect(column($staffId, 'phone'))->toBe('+966505550000')
            ->and(column($staffId, 'phone_verified_at'))->not->toBeNull();
    });

    it('refuses a phone another staff member uses when it is entered', function () {
        $staffId = Fx::staff();
        $taken = (string) column(Fx::staff(), 'phone');
        Fx::actAsStaff($staffId);
        Fx::withoutStaffUniqueIndexes();

        expect(fn () => app(RequestOwnPhoneChangeHandler::class)->handle(new RequestOwnPhoneChange($taken)))->toThrow(PhoneAlreadyInUse::class)
            ->and(received()->codes)->toBe([]);
    });

    it('refuses the new phone when someone else took it after the code was sent', function () {
        $staffId = Fx::staff();
        $old = column($staffId, 'phone');
        Fx::actAsStaff($staffId);
        app(RequestOwnPhoneChangeHandler::class)->handle(new RequestOwnPhoneChange('+966505550000'));
        Fx::withoutStaffUniqueIndexes();
        DB::table('access.staff_users')->where('id', Fx::staff())->update(['phone' => '+966505550000']);

        expect(fn () => app(VerifyOwnPhoneChangeHandler::class)->handle(new VerifyOwnPhoneChange(received()->lastCode())))->toThrow(PhoneAlreadyInUse::class)
            ->and(column($staffId, 'phone'))->toBe($old);
    });

    it('switches notification toggles, and Ops reads them through AccessApi', function () {
        $staffId = Fx::staff();
        DB::table('access.staff_notification_preferences')->insert(array_map(
            fn (StaffNotificationTopic $topic): array => ['staff_user_id' => $staffId, 'topic' => $topic->value, 'email' => false, 'panel' => true],
            StaffNotificationTopic::cases(),
        ));
        Fx::actAsStaff($staffId);

        app(UpdateOwnNotificationPreferencesHandler::class)->handle(new UpdateOwnNotificationPreferences([
            'NEW_ORDERS' => ['email' => true, 'panel' => true],
            'LOW_STOCK' => ['email' => false, 'panel' => false],
        ]));

        $preferences = [];

        foreach (app(AccessApi::class)->staffNotificationPreferences($staffId) as $preference) {
            $preferences[$preference->topic->value] = [$preference->email, $preference->panel];
        }

        expect($preferences)->toBe([
            'NEW_ORDERS' => [true, true],
            'COMPANY_APPLICATIONS' => [false, true],
            'LOW_STOCK' => [false, false],
            'CAMPAIGN_EXPIRY' => [false, true],
        ])->and(fn () => app(UpdateOwnNotificationPreferencesHandler::class)->handle(new UpdateOwnNotificationPreferences(['GOSSIP' => ['email' => true, 'panel' => true]])))
            ->toThrow(InvalidAccessAttribute::class);
    });

    it('needs someone signed in as staff: the system has no own account', function () {
        expect(fn () => app(UpdateOwnStaffProfileHandler::class)->handle(new UpdateOwnStaffProfile('A', 'B', 'C', '1990-01-01', 'SA', null, 'ar', null)))
            ->toThrow(Unauthorized::class);
    });

    it('answers other modules through AccessApi', function () {
        $staffId = Fx::staff(firstName: 'Khalid');

        expect(app(AccessApi::class)->staff($staffId)?->firstName)->toBe('Khalid')
            ->and(app(AccessApi::class)->staff('01j8z3k4m5n6p7q8r9s0t1v2w3'))->toBeNull()
            ->and(app(AccessApi::class)->staffNotificationPreferences('01j8z3k4m5n6p7q8r9s0t1v2w3'))->toBe([]);
    });
});

describe('avatars as Platform media', function () {
    it('leaves the staff member without an avatar when the image is deleted', function () {
        Storage::fake('local');
        $staffId = Fx::staff();
        $image = publicImage();
        DB::table('access.staff_users')->where('id', $staffId)->update(['avatar_media_id' => $image]);

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($image));

        expect(column($staffId, 'avatar_media_id'))->toBeNull()
            ->and(Fx::audits('access.staff_user.avatar_detached', $staffId))->toBe(1);
    });

    it('refuses the delete for someone who may not edit that staff member', function (Closure $owner, string $error) {
        Storage::fake('local');
        $staffId = $owner();
        $image = publicImage();
        DB::table('access.staff_users')->where('id', $staffId)->update(['avatar_media_id' => $image]);
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_UPDATE, PlatformPermissions::MEDIA_DELETE]);

        expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($image)))->toThrow($error)
            ->and(column($staffId, 'avatar_media_id'))->toBe($image);
    })->with([
        'staff outside the admin\'s stores' => [fn () => Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['ae']), Unauthorized::class],
        'another admin' => [fn () => Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], RoleLevel::Admin), StaffNotEditable::class],
        'a Super Admin' => [fn () => Fx::staff(superAdmin: true), StaffNotEditable::class],
    ]);

    it('lets staff who may delete media delete their own avatar', function () {
        Storage::fake('local');
        $staffId = Fx::staffWith([PlatformPermissions::MEDIA_DELETE], ['sa']);
        $image = publicImage();
        DB::table('access.staff_users')->where('id', $staffId)->update(['avatar_media_id' => $image]);
        Fx::actAsStaff($staffId);

        app(DeleteMediaHandler::class)->handle(new DeleteMedia($image));

        expect(column($staffId, 'avatar_media_id'))->toBeNull();
    });
});

describe('nobody works without a role (owner, 2026-09-19)', function () {
    it('enables someone with no role only together with one, given by the same admin', function () {
        $staffId = Fx::staff(StaffStatus::Disabled);
        Fx::actAsAdmin(['sa'], [...ACCOUNT_ADMIN, AccessPermissions::STAFF_ASSIGN_ROLE]);

        expect(fn () => app(EnableStaffHandler::class)->handle(new EnableStaff($staffId)))->toThrow(InvalidAccessAttribute::class)
            ->and(column($staffId, 'status'))->toBe('DISABLED');

        app(EnableStaffHandler::class)->handle(new EnableStaff($staffId, Fx::change($staffId, ['sa'], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]))));
        Fx::actAsStaff($staffId);

        expect(column($staffId, 'status'))->toBe('ACTIVE')
            ->and(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa')))->toBeTrue();
    });

    it('undoes the role when the enabling itself is refused', function () {
        $staffId = Fx::staff(StaffStatus::Disabled);
        // Gives roles in KSA and the UAE, but disables and enables staff only in KSA.
        Fx::actAsAdmin(['sa', 'ae'], [AccessPermissions::STAFF_ASSIGN_ROLE, AccessPermissions::STAFF_DISABLE, PlatformPermissions::STORE_UPDATE], [AccessPermissions::STAFF_DISABLE => ['sa']]);

        expect(fn () => app(EnableStaffHandler::class)->handle(new EnableStaff($staffId, Fx::change($staffId, ['sa', 'ae'], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE])))))->toThrow(Unauthorized::class)
            ->and(column($staffId, 'status'))->toBe('DISABLED')
            ->and(Fx::roleOf($staffId))->toBe('');
    });

    it('refuses a role given for someone else', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        DB::table('access.staff_users')->where('id', $staffId)->update(['status' => 'DISABLED']);
        $other = Fx::staff();
        Fx::actAsAdmin(['sa'], [...ACCOUNT_ADMIN, AccessPermissions::STAFF_ASSIGN_ROLE]);

        expect(fn () => app(EnableStaffHandler::class)->handle(new EnableStaff($staffId, Fx::change($other, ['sa'], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE])))))->toThrow(InvalidAccessAttribute::class)
            ->and(column($staffId, 'status'))->toBe('DISABLED')
            ->and(Fx::roleOf($other))->toBe('');
    });
});

describe('what each change leaves behind (review of step 3a)', function () {
    it('takes every permission away at once when disabling, and gives them back when enabling', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $adminId = Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);
        Fx::warmCache($staffId);

        app(DisableStaffHandler::class)->handle(new DisableStaff($staffId));
        Fx::actAsStaff($staffId);
        expect(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa')))->toBeFalse();

        Fx::warmCache($staffId);
        Fx::actAsStaff($adminId);
        app(EnableStaffHandler::class)->handle(new EnableStaff($staffId));
        Fx::actAsStaff($staffId);
        expect(Fx::allows(PlatformPermissions::STORE_UPDATE, Fx::inStore('sa')))->toBeTrue();
    });

    it('dispatches its events only once the change commits, and none for a new invitation', function () {
        Event::fake([StaffDisabled::class, StaffActivated::class]);
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);

        expect(fn () => DB::transaction(function () use ($staffId): void {
            app(DisableStaffHandler::class)->handle(new DisableStaff($staffId));

            throw new RuntimeException('rolled back');
        }))->toThrow(RuntimeException::class)
            ->and(column($staffId, 'status'))->toBe('ACTIVE');
        Event::assertNotDispatched(StaffDisabled::class);

        $invited = Fx::staff(StaffStatus::Invited);
        Fx::assign($invited, Fx::role([PlatformPermissions::STORE_UPDATE]), ['sa']);
        DB::table('access.staff_users')->where('id', $invited)->update(['status' => 'DISABLED']);
        app(EnableStaffHandler::class)->handle(new EnableStaff($invited));

        Event::assertNotDispatched(StaffActivated::class);
    });

    it('refuses a new email for a disabled account, and a disabled account\'s pending link dies', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);
        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'new.address@example.test'));
        $token = received()->lastEmailChangeToken();

        app(DisableStaffHandler::class)->handle(new DisableStaff($staffId));

        expect(fn () => app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'other@example.test')))->toThrow(InvalidStaffStatus::class);

        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));
        expect(fn () => app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange($token)))->toThrow(InvalidOrExpiredLink::class);
    });

    it('lets the link change the email only while its requester could still make the change', function (Closure $meanwhile) {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $old = column($staffId, 'email');
        $adminId = Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);
        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'new.address@example.test'));

        $meanwhile($adminId, $staffId);
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(fn () => app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange(received()->lastEmailChangeToken())))->toThrow(InvalidOrExpiredLink::class)
            ->and(column($staffId, 'email'))->toBe($old);
    })->with([
        'the requester was disabled' => function (string $adminId): void {
            DB::table('access.staff_users')->where('id', $adminId)->update(['status' => 'DISABLED']);
            app(GrantsReader::class)->refresh($adminId);
        },
        'the requester lost "edit staff"' => function (string $adminId): void {
            Fx::assign($adminId, Fx::role([AccessPermissions::STAFF_DISABLE, PlatformPermissions::STORE_UPDATE], RoleLevel::Admin), ['sa']);
        },
        'the requester no longer holds the person\'s actions' => function (string $adminId, string $staffId): void {
            Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE, PlatformPermissions::SETTINGS_UPDATE]), ['sa']);
        },
        'the person became an admin' => function (string $adminId, string $staffId): void {
            Fx::assign($staffId, Fx::role([PlatformPermissions::STORE_UPDATE], RoleLevel::Admin), ['sa']);
        },
    ]);

    it('never changes the email of an account that is not active, even through a link left over', function (string $status) {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $old = column($staffId, 'email');
        DB::table('access.staff_users')->where('id', $staffId)->update(['status' => $status, 'password' => $status === 'INVITED' ? null : 'hash', 'phone_verified_at' => null]);
        DB::table('access.staff_email_changes')->insert([
            'staff_user_id' => $staffId, 'new_email' => 'new.address@example.test', 'token_hash' => hash('sha256', 'left-over'),
            'expires_at' => now()->addDay(), 'requested_by' => null, 'created_at' => now(),
        ]);
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(fn () => app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange('left-over')))->toThrow(InvalidOrExpiredLink::class)
            ->and(column($staffId, 'email'))->toBe($old);
    })->with(['DISABLED', 'INVITED']);

    it('keeps the link alive for 72 hours, and a second request replaces the first', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);
        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'first@example.test'));
        $first = received()->lastEmailChangeToken();
        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, 'second@example.test'));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHours(72)->subMinute());
        Fx::actAs(Actor::guest(strtolower((string) Str::ulid())));

        expect(fn () => app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange($first)))->toThrow(InvalidOrExpiredLink::class);

        app(ConfirmStaffEmailChangeHandler::class)->handle(new ConfirmStaffEmailChange(received()->lastEmailChangeToken()));

        expect(column($staffId, 'email'))->toBe('second@example.test')
            ->and(Fx::audits('access.staff_user.email_change_requested', $staffId))->toBe(2)
            ->and(Fx::audits('access.staff_user.email_changed', $staffId))->toBe(1);
    });

    it('refuses the same email, but takes the same one in other letters', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $email = (string) column($staffId, 'email');
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);

        expect(fn () => app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, $email)))->toThrow(InvalidAccessAttribute::class);

        app(ChangeStaffEmailHandler::class)->handle(new ChangeStaffEmail($staffId, strtoupper($email)));

        expect(received()->emailChanges[0]['to'])->toBe(strtoupper($email));
    });

    it('keeps a phone verified when a profile is saved with the same number', function () {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        $verifiedAt = column($staffId, 'phone_verified_at');
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);

        app(UpdateStaffProfileHandler::class)->handle(profileUpdate($staffId, (string) column($staffId, 'phone'), lastName: 'Harbi'));

        $audit = (string) DB::table('platform.audit_entries')->where('subject_id', $staffId)->where('action', 'access.staff_user.profile_updated')->value('changes');

        expect(column($staffId, 'phone_verified_at'))->toBe($verifiedAt)
            ->and(json_decode($audit, true))->toBe(['address' => 'changed', 'last_name' => 'changed']);
    });

    it('refuses your own verified number as a new one, but sends a code to confirm a number an admin entered', function () {
        $staffId = Fx::staff();
        $phone = (string) column($staffId, 'phone');
        Fx::actAsStaff($staffId);

        expect(fn () => app(RequestOwnPhoneChangeHandler::class)->handle(new RequestOwnPhoneChange($phone)))->toThrow(InvalidAccessAttribute::class);

        DB::table('access.staff_users')->where('id', $staffId)->update(['phone_verified_at' => null]);
        app(RequestOwnPhoneChangeHandler::class)->handle(new RequestOwnPhoneChange($phone));
        app(VerifyOwnPhoneChangeHandler::class)->handle(new VerifyOwnPhoneChange(received()->lastCode()));

        expect(column($staffId, 'phone_verified_at'))->not->toBeNull()
            ->and(Fx::audits('access.staff_user.phone_changed', $staffId))->toBe(1);
    });

    it('records the job title and country, like the rest of the profile, only as changed', function () {
        $staffId = Fx::staff();
        Fx::actAsStaff($staffId);

        app(UpdateOwnStaffProfileHandler::class)->handle(new UpdateOwnStaffProfile('Staff', 'Member', 'Lead tester', '1990-01-01', 'EG', null, 'ar', null));
        app(UpdateOwnNotificationPreferencesHandler::class)->handle(new UpdateOwnNotificationPreferences(['NEW_ORDERS' => ['email' => true, 'panel' => true]]));

        $audit = (string) DB::table('platform.audit_entries')->where('subject_id', $staffId)->where('action', 'access.staff_user.own_profile_updated')->value('changes');

        expect(json_decode($audit, true))->toMatchArray(['job_title' => 'changed', 'country' => 'changed'])
            ->and(Fx::audits('access.staff_user.notification_preferences_updated', $staffId))->toBe(1);
        expect($audit)->not->toContain('Lead tester');
        expect($audit)->not->toContain('EG');
    });

    it('takes only an uploaded public image as an avatar, from an admin or the person', function (Closure $update) {
        $staffId = Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa']);
        Fx::actAsAdmin(['sa'], ACCOUNT_ADMIN);

        expect(fn () => $update($staffId))->toThrow(InvalidAccessAttribute::class)
            ->and(column($staffId, 'avatar_media_id'))->toBeNull();
    })->with([
        'a PDF, by an admin' => fn (string $id) => app(UpdateStaffProfileHandler::class)->handle(profileUpdate($id, (string) column($id, 'phone'), publicImage(mime: 'application/pdf'))),
        'a PDF, by the person' => function (string $id): void {
            Fx::actAsStaff($id);
            app(UpdateOwnStaffProfileHandler::class)->handle(new UpdateOwnStaffProfile('Staff', 'Member', 'Tester', '1990-01-01', 'SA', null, 'en', publicImage(mime: 'application/pdf')));
        },
        'a private file, by the person' => function (string $id): void {
            Fx::actAsStaff($id);
            app(UpdateOwnStaffProfileHandler::class)->handle(new UpdateOwnStaffProfile('Staff', 'Member', 'Tester', '1990-01-01', 'SA', null, 'en', publicImage('PRIVATE')));
        },
    ]);

    it('takes an optional avatar at invitation, only an uploaded public image', function () {
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_INVITE, AccessPermissions::STAFF_ASSIGN_ROLE, PlatformPermissions::STORE_UPDATE]);
        $invite = fn (?string $avatar, string $email): string => app(InviteStaffHandler::class)->handle(new InviteStaff(
            $email, 'Noura', 'Saleh', 'Store keeper', '1995-03-10', 'SA', null, Fx::phone(), 'en',
            AccessLevel::SelectedStores, [Fx::storeId('sa')], savedRoleId: Fx::role([PlatformPermissions::STORE_UPDATE]), avatarMediaId: $avatar,
        ));
        $image = publicImage();

        expect(column($invite($image, 'with.photo@example.test'), 'avatar_media_id'))->toBe($image)
            ->and(fn () => $invite(publicImage(mime: 'application/pdf'), 'with.pdf@example.test'))->toThrow(InvalidAccessAttribute::class)
            ->and(DB::table('access.staff_users')->where('email', 'with.pdf@example.test')->exists())->toBeFalse();
    });

    it('never lets deleting a photo edit someone the deleter may not edit', function (Closure $owner, Closure $deleter, string $error) {
        Storage::fake('local');
        $staffId = $owner();
        $image = publicImage();
        DB::table('access.staff_users')->where('id', $staffId)->update(['avatar_media_id' => $image]);
        $deleter();

        expect(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($image)))->toThrow($error)
            ->and(column($staffId, 'avatar_media_id'))->toBe($image);
    })->with([
        'another Super Admin\'s, by a Super Admin' => [fn () => Fx::staff(superAdmin: true), fn () => Fx::actAsStaff(Fx::staff(superAdmin: true)), StaffNotEditable::class],
        'someone with no role, by an admin without "edit staff"' => [fn () => Fx::staff(), fn () => Fx::actAsAdmin(['sa'], [PlatformPermissions::MEDIA_DELETE, PlatformPermissions::STORE_UPDATE]), Unauthorized::class],
    ]);
});
