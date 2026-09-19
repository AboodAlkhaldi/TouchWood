<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Access\Public\Enums\StaffStatus;

/**
 * A staff account (Access spec §1.4, §4.3).
 *
 *    invite ──▶ INVITED ── accept (password + phone code) ──▶ ACTIVE
 *                 │ cancel                          disable │  ▲ enable
 *                 ▼                                         ▼  │
 *              DISABLED ◀─────────────────────────────── DISABLED
 *
 * Enabling someone who never accepted takes them back to INVITED: they have no password yet.
 * Never deleted: the audit log names them forever.
 */
final class StaffUser
{
    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly string $id,
        private EmailAddress $email,
        private ?string $passwordHash,
        private StaffProfile $profile,
        private ?PhoneNumber $phone,
        private ?DateTimeImmutable $phoneVerifiedAt,
        private ?string $avatarMediaId,
        private Language $language,
        private StaffStatus $status,
        private bool $superAdmin,
    ) {}

    /**
     * The whole profile is required at invitation; the phone is verified when the invitation is
     * accepted (owner's decision, 2026-09-19).
     */
    public static function invite(string $id, EmailAddress $email, StaffProfile $profile, PhoneNumber $phone, Language $language, bool $superAdmin = false): self
    {
        return new self($id, $email, null, $profile, $phone, null, null, $language, StaffStatus::Invited, $superAdmin);
    }

    public static function reconstitute(
        string $id,
        EmailAddress $email,
        ?string $passwordHash,
        StaffProfile $profile,
        ?PhoneNumber $phone,
        ?DateTimeImmutable $phoneVerifiedAt,
        ?string $avatarMediaId,
        Language $language,
        StaffStatus $status,
        bool $superAdmin,
    ): self {
        return new self($id, $email, $passwordHash, $profile, $phone, $phoneVerifiedAt, $avatarMediaId, $language, $status, $superAdmin);
    }

    /**
     * The invitation is accepted: the password the invitee chose, and the phone whose code they
     * entered — possibly correcting a number the admin mistyped. Only now is a password stored, so
     * a staff member with a password is one who accepted.
     */
    public function accept(string $passwordHash, PhoneNumber $phone, DateTimeImmutable $at): void
    {
        $this->requireStatus(StaffStatus::Invited);

        $this->passwordHash = $passwordHash;
        $this->phone = $phone;
        $this->phoneVerifiedAt = $at;
        $this->status = StaffStatus::Active;
        $this->markChanged('password');
        $this->markChanged('phone');
        $this->markChanged('status');
    }

    /**
     * Also cancels an invitation (spec §4.3).
     */
    public function disable(): void
    {
        if ($this->status === StaffStatus::Disabled) {
            throw new InvalidStaffStatus($this->status);
        }

        $this->status = StaffStatus::Disabled;
        $this->markChanged('status');
    }

    public function enable(): void
    {
        $this->requireStatus(StaffStatus::Disabled);
        $this->status = $this->hasAccepted() ? StaffStatus::Active : StaffStatus::Invited;
        $this->markChanged('status');
    }

    public function updateProfile(StaffProfile $profile): void
    {
        if (! $this->profile->equals($profile)) {
            $this->profile = $profile;
            $this->markChanged('profile');
        }
    }

    public function changeLanguage(Language $language): void
    {
        if ($this->language !== $language) {
            $this->language = $language;
            $this->markChanged('locale');
        }
    }

    public function changeAvatar(?string $mediaId): void
    {
        if ($this->avatarMediaId !== $mediaId) {
            $this->avatarMediaId = $mediaId;
            $this->markChanged('avatar_media_id');
        }
    }

    /**
     * An admin's change (a lost phone): the staff member verifies the new number at their next
     * sign-in (spec §1.4).
     */
    public function replacePhone(PhoneNumber $phone): void
    {
        if ($this->phone !== null && $this->phone->equals($phone)) {
            return;
        }

        $this->phone = $phone;
        $this->phoneVerifiedAt = null;
        $this->markChanged('phone');
    }

    /**
     * The staff member's own change, after the new number's code was right.
     */
    public function verifyPhone(PhoneNumber $phone, DateTimeImmutable $at): void
    {
        $this->phone = $phone;
        $this->phoneVerifiedAt = $at;
        $this->markChanged('phone');
    }

    /**
     * A lost Super Admin phone (amendment 14): a new number is verified at the next sign-in.
     */
    public function resetPhone(): void
    {
        $this->phone = null;
        $this->phoneVerifiedAt = null;
        $this->markChanged('phone');
    }

    /**
     * After the link sent to the new address was used (amendment 17).
     */
    public function changeEmail(EmailAddress $email): void
    {
        if (! $this->email->sameAs($email) || $this->email->value !== $email->value) {
            $this->email = $email;
            $this->markChanged('email');
        }
    }

    public function promoteToSuperAdmin(): void
    {
        if (! $this->superAdmin) {
            $this->superAdmin = true;
            $this->markChanged('is_super_admin');
        }
    }

    public function revokeSuperAdmin(): void
    {
        if ($this->superAdmin) {
            $this->superAdmin = false;
            $this->markChanged('is_super_admin');
        }
    }

    /**
     * @return list<string>
     */
    public function pullChanges(): array
    {
        [$changed, $this->changed] = [$this->changed, []];

        return $changed;
    }

    /**
     * A password is stored only when an invitation is accepted.
     */
    public function hasAccepted(): bool
    {
        return $this->passwordHash !== null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    public function passwordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function profile(): StaffProfile
    {
        return $this->profile;
    }

    public function firstName(): string
    {
        return $this->profile->firstName;
    }

    public function lastName(): string
    {
        return $this->profile->lastName;
    }

    public function phone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function phoneVerifiedAt(): ?DateTimeImmutable
    {
        return $this->phoneVerifiedAt;
    }

    public function avatarMediaId(): ?string
    {
        return $this->avatarMediaId;
    }

    public function language(): Language
    {
        return $this->language;
    }

    public function status(): StaffStatus
    {
        return $this->status;
    }

    /**
     * Set only by the console command (spec §1.6). A Super Admin has no role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    private function requireStatus(StaffStatus $status): void
    {
        if ($this->status !== $status) {
            throw new InvalidStaffStatus($this->status);
        }
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }
}
