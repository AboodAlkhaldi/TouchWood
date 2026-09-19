<?php

declare(strict_types=1);

use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\ValueObject\CountryCode;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Access\Public\Enums\StaffStatus;

function staffProfile(): StaffProfile
{
    return StaffProfile::of('Sara', 'Ali', 'Store manager', '1990-05-01', 'SA', null);
}

function invitedStaff(): StaffUser
{
    return StaffUser::invite('s1', EmailAddress::of('sara@example.test'), staffProfile(), PhoneNumber::of('+966501234567'), Language::Arabic, 'inviter');
}

describe('email addresses', function () {
    it('keeps the address as typed, trimmed, and compares ignoring case', function () {
        $email = EmailAddress::of('  Sara@Example.test ');

        expect($email->value)->toBe('Sara@Example.test')
            ->and($email->sameAs(EmailAddress::of('sara@example.TEST')))->toBeTrue();
    });

    it('refuses what is not an email address', function (string $value) {
        EmailAddress::of($value);
    })->throws(InvalidAccessAttribute::class)->with(['', 'sara', 'sara@', '@example.test']);

    it('takes 254 characters, the column\'s size, and refuses 255', function () {
        $domain = implode('.', [str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 52)]).'.test';
        $fits = str_repeat('a', 64).'@eee.'.$domain;
        $tooLong = str_repeat('a', 64).'@eeee.'.$domain;

        expect(strlen($fits))->toBe(254)
            ->and(EmailAddress::of($fits)->value)->toBe($fits)
            ->and(strlen($tooLong))->toBe(255)
            ->and(fn () => EmailAddress::of($tooLong))->toThrow(InvalidAccessAttribute::class);
    });
});

describe('phone numbers', function () {
    it('normalises to E.164, from any country', function (string $typed, string $stored) {
        expect(PhoneNumber::of($typed)->value)->toBe($stored);
    })->with([
        'KSA with spaces' => ['+966 50 123 4567', '+966501234567'],
        'Egypt with dashes' => ['+20-100-123-4567', '+201001234567'],
        'a leading 00' => ['00971501234567', '+971501234567'],
        'UK in brackets' => ['+44 (20) 7946 0958', '+442079460958'],
    ]);

    it('refuses a number without its country code, or too short', function (string $value) {
        PhoneNumber::of($value);
    })->throws(InvalidAccessAttribute::class)->with(['0501234567', '+12345', '+0501234567', 'phone']);
});

describe('countries', function () {
    it('knows the 249 ISO 3166-1 countries, and nothing else', function () {
        // A change in PHP's intl data shows here first.
        expect(CountryCode::all())->toHaveCount(249)
            ->and(CountryCode::of('sa')->value)->toBe('SA');
    });

    it('refuses a code that is not a country', function (string $value) {
        CountryCode::of($value);
    })->throws(InvalidAccessAttribute::class)->with(['XX', 'EU', 'UN', 'ZZ', 'KSA', '']);
});

describe('profiles', function () {
    it('needs the names, job title, a past birth date after 1900 and a country; the address is optional', function (Closure $make) {
        expect($make)->toThrow(InvalidAccessAttribute::class);
    })->with([
        'no first name' => [fn () => StaffProfile::of(' ', 'Ali', 'Manager', '1990-05-01', 'SA', null)],
        'a name too long' => [fn () => StaffProfile::of(str_repeat('a', 101), 'Ali', 'Manager', '1990-05-01', 'SA', null)],
        'no job title' => [fn () => StaffProfile::of('Sara', 'Ali', '', '1990-05-01', 'SA', null)],
        'a birth date in the future' => [fn () => StaffProfile::of('Sara', 'Ali', 'Manager', '2999-01-01', 'SA', null)],
        'born before 1900' => [fn () => StaffProfile::of('Sara', 'Ali', 'Manager', '1899-12-31', 'SA', null)],
        'born on 1 January 1900, which the database refuses too' => [fn () => StaffProfile::of('Sara', 'Ali', 'Manager', '1900-01-01', 'SA', null)],
        'not a date' => [fn () => StaffProfile::of('Sara', 'Ali', 'Manager', '1990-02-30', 'SA', null)],
        'an address too long' => [fn () => StaffProfile::of('Sara', 'Ali', 'Manager', '1990-05-01', 'SA', str_repeat('a', 501))],
    ]);

    it('treats a blank address as none', function () {
        expect(StaffProfile::of('Sara', 'Ali', 'Manager', '1990-05-01', 'SA', '  ')->address)->toBeNull();
    });

    it('takes ar or en as the communication language', function () {
        expect(Language::of('EN'))->toBe(Language::English);
        expect(fn () => Language::of('fr'))->toThrow(InvalidAccessAttribute::class);
    });
});

describe('a staff member\'s life', function () {
    it('starts invited, with no password and an unverified phone', function () {
        $staff = invitedStaff();

        expect($staff->status())->toBe(StaffStatus::Invited)
            ->and($staff->passwordHash())->toBeNull()
            ->and($staff->phoneVerifiedAt())->toBeNull()
            ->and($staff->hasAccepted())->toBeFalse();
    });

    it('becomes active on accepting, with the password and the verified phone', function () {
        $staff = invitedStaff();
        $staff->accept('hash', PhoneNumber::of('+966509999999'), new DateTimeImmutable('2026-09-19 10:00'));

        expect($staff->status())->toBe(StaffStatus::Active)
            ->and($staff->phone()?->value)->toBe('+966509999999')
            ->and($staff->phoneVerifiedAt()?->format('Y-m-d H:i'))->toBe('2026-09-19 10:00')
            ->and($staff->hasAccepted())->toBeTrue();
    });

    it('is disabled and enabled only after accepting', function () {
        $staff = invitedStaff();
        $staff->accept('hash', PhoneNumber::of('+966509999999'), new DateTimeImmutable);
        $staff->disable();

        expect($staff->status())->toBe(StaffStatus::Disabled);

        $staff->enable();

        expect($staff->status())->toBe(StaffStatus::Active);
    });

    it('never enables a disabled record that has no password, whatever the database holds', function () {
        $staff = StaffUser::reconstitute(
            's1', EmailAddress::of('sara@example.test'), null, staffProfile(), PhoneNumber::of('+966501234567'), null, null,
            Language::Arabic, StaffStatus::Disabled, false, 'inviter',
        );

        expect(fn () => $staff->enable())->toThrow(InvalidStaffStatus::class)
            ->and($staff->status())->toBe(StaffStatus::Disabled);
    });

    it('is cancelled only while invited, for good', function () {
        $staff = invitedStaff();
        $staff->cancel();

        expect($staff->status())->toBe(StaffStatus::Cancelled)
            ->and($staff->invitedBy())->toBe('inviter')
            ->and(fn () => $staff->accept('hash', PhoneNumber::of('+966509999999'), new DateTimeImmutable))->toThrow(InvalidStaffStatus::class)
            ->and(fn () => $staff->enable())->toThrow(InvalidStaffStatus::class)
            ->and(fn () => $staff->disable())->toThrow(InvalidStaffStatus::class);
    });

    it('refuses what its state does not allow', function (Closure $act) {
        expect($act)->toThrow(InvalidStaffStatus::class);
    })->with([
        'accept twice' => [function () {
            $staff = invitedStaff();
            $staff->accept('hash', PhoneNumber::of('+966509999999'), new DateTimeImmutable);
            $staff->accept('hash', PhoneNumber::of('+966509999999'), new DateTimeImmutable);
        }],
        'disable someone invited' => [fn () => invitedStaff()->disable()],
        'disable twice' => [function () {
            $staff = invitedStaff();
            $staff->accept('hash', PhoneNumber::of('+966509999999'), new DateTimeImmutable);
            $staff->disable();
            $staff->disable();
        }],
        'enable someone active' => [function () {
            $staff = invitedStaff();
            $staff->accept('hash', PhoneNumber::of('+966509999999'), new DateTimeImmutable);
            $staff->enable();
        }],
        'enable someone invited' => [fn () => invitedStaff()->enable()],
        'cancel someone who accepted' => [function () {
            $staff = invitedStaff();
            $staff->accept('hash', PhoneNumber::of('+966509999999'), new DateTimeImmutable);
            $staff->cancel();
        }],
    ]);

    it('leaves a phone an admin changed unverified, and a reset phone empty', function () {
        $staff = invitedStaff();
        $staff->accept('hash', PhoneNumber::of('+966509999999'), new DateTimeImmutable);
        $staff->replacePhone(PhoneNumber::of('+966508888888'));

        expect($staff->phoneVerifiedAt())->toBeNull();

        $staff->resetPhone();

        expect($staff->phone())->toBeNull();
    });

    it('records what changed, once', function () {
        $staff = invitedStaff();
        $staff->updateProfile(staffProfile());
        $staff->changeLanguage(Language::Arabic);

        expect($staff->pullChanges())->toBe([]);

        $staff->updateProfile(StaffProfile::of('Sara', 'Ahmed', 'Store manager', '1990-05-01', 'SA', null));
        $staff->changeLanguage(Language::English);
        $staff->promoteToSuperAdmin();

        expect($staff->pullChanges())->toBe(['profile', 'locale', 'is_super_admin']);
    });
});
