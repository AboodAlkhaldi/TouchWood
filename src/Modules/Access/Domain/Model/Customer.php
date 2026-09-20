<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;

/**
 * A customer account (Access spec §1.1, §1.2, §4.1): one account for every store.
 *
 * What never changes: the email (a customer who needs another one registers again), the account
 * type, and the home store. What only moves forward: a verified email is never unverified again,
 * and once a phone is verified the account always has one. Blocking stops signing in; it is not a
 * deletion, which is a separate column (step 6).
 */
final class Customer
{
    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly string $id,
        private readonly EmailAddress $email,
        private string $passwordHash,
        private string $firstName,
        private string $lastName,
        private readonly AccountType $accountType,
        private CustomerStatus $status,
        private ?DateTimeImmutable $emailVerifiedAt,
        private ?PhoneNumber $phone,
        private ?DateTimeImmutable $phoneVerifiedAt,
        private Language $language,
        private readonly string $homeStoreId,
        private string $lastStoreId,
        private readonly string $termsVersion,
        private readonly DateTimeImmutable $termsAcceptedAt,
        private ?DateTimeImmutable $deletionScheduledFor = null,
        private int $sessionVersion = 0,
    ) {}

    /**
     * A new account: active at once, with the email unverified and no phone yet. Only ordering
     * waits for both verifications (spec §1.2); browsing and a cart do not.
     *
     * @param  string  $storeId  the store they registered in: their home store, fixed
     * @param  string  $termsVersion  the version of that store's terms they accepted (amendment 37)
     */
    public static function register(
        string $id,
        EmailAddress $email,
        string $passwordHash,
        string $firstName,
        string $lastName,
        AccountType $accountType,
        Language $language,
        string $storeId,
        string $termsVersion,
        DateTimeImmutable $at,
    ): self {
        return new self(
            $id, $email, $passwordHash, $firstName, $lastName, $accountType, CustomerStatus::Active,
            null, null, null, $language, $storeId, $storeId, $termsVersion, $at,
        );
    }

    public static function reconstitute(
        string $id,
        EmailAddress $email,
        string $passwordHash,
        string $firstName,
        string $lastName,
        AccountType $accountType,
        CustomerStatus $status,
        ?DateTimeImmutable $emailVerifiedAt,
        ?PhoneNumber $phone,
        ?DateTimeImmutable $phoneVerifiedAt,
        Language $language,
        string $homeStoreId,
        string $lastStoreId,
        string $termsVersion,
        DateTimeImmutable $termsAcceptedAt,
        ?DateTimeImmutable $deletionScheduledFor = null,
        int $sessionVersion = 0,
    ): self {
        if ($sessionVersion < 0) {
            throw new InvalidAccessAttribute('session_version', 'zero or more');
        }

        return new self(
            $id, $email, $passwordHash, $firstName, $lastName, $accountType, $status,
            $emailVerifiedAt, $phone, $phoneVerifiedAt, $language, $homeStoreId, $lastStoreId,
            $termsVersion, $termsAcceptedAt, $deletionScheduledFor, $sessionVersion,
        );
    }

    /**
     * A new password — changed by its owner or reset by email link. Every session signed in before
     * it ends (spec §1.8): the session version moves on.
     */
    public function changePassword(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
        $this->sessionVersion++;
        $this->markChanged('password');
    }

    /**
     * Where they last shopped: after signing in, on any device, they land there (spec §1.1).
     */
    public function moveToStore(string $storeId): void
    {
        if ($this->lastStoreId === $storeId) {
            return;
        }

        $this->lastStoreId = $storeId;
        $this->markChanged('last_store_id');
    }

    /**
     * The verification link was used. Verifying again changes nothing: the first time stands.
     */
    public function verifyEmail(DateTimeImmutable $at): void
    {
        if ($this->emailVerifiedAt !== null) {
            return;
        }

        $this->emailVerifiedAt = $at;
        $this->markChanged('email_verified_at');
    }

    /**
     * The code sent to this number was right: it becomes the account's phone, verified. The old
     * number stayed live until now (spec §1.3), and a phone is never removed once set.
     */
    public function verifyPhone(PhoneNumber $phone, DateTimeImmutable $at): void
    {
        if ($this->phone !== null && $this->phone->equals($phone) && $this->phoneVerifiedAt !== null) {
            return;
        }

        $this->phone = $phone;
        $this->phoneVerifiedAt = $at;
        $this->markChanged('phone');
    }

    public function updateName(string $firstName, string $lastName): void
    {
        if ($this->firstName === $firstName && $this->lastName === $lastName) {
            return;
        }

        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->markChanged('name');
    }

    public function changeLanguage(Language $language): void
    {
        if ($this->language === $language) {
            return;
        }

        $this->language = $language;
        $this->markChanged('locale');
    }

    /**
     * The person's part of handoff §7.4: Sales adds the company's part for a company account.
     * Scheduling a deletion is step 6; the column is read here so this answer is never wrong.
     */
    public function mayOrder(): bool
    {
        return $this->status === CustomerStatus::Active
            && $this->emailVerifiedAt !== null
            && $this->phoneVerifiedAt !== null
            && $this->deletionScheduledFor === null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function accountType(): AccountType
    {
        return $this->accountType;
    }

    public function status(): CustomerStatus
    {
        return $this->status;
    }

    public function emailVerifiedAt(): ?DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function phone(): ?PhoneNumber
    {
        return $this->phone;
    }

    public function phoneVerifiedAt(): ?DateTimeImmutable
    {
        return $this->phoneVerifiedAt;
    }

    public function language(): Language
    {
        return $this->language;
    }

    public function homeStoreId(): string
    {
        return $this->homeStoreId;
    }

    public function lastStoreId(): string
    {
        return $this->lastStoreId;
    }

    public function termsVersion(): string
    {
        return $this->termsVersion;
    }

    public function termsAcceptedAt(): DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    /**
     * Set while a deletion is pending (spec §1.10, built in step 6): they cannot order meanwhile.
     */
    public function deletionScheduledFor(): ?DateTimeImmutable
    {
        return $this->deletionScheduledFor;
    }

    /**
     * A session signed in under an older version has ended (spec §1.8).
     */
    public function sessionVersion(): int
    {
        return $this->sessionVersion;
    }

    /**
     * What changed since the last call, for the audit entry; the list starts again.
     *
     * @return list<string>
     */
    public function pullChanges(): array
    {
        $changed = $this->changed;
        $this->changed = [];

        return $changed;
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }
}
