<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidCustomerStatus;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;

/**
 * A customer account (Access spec §1.1, §1.2, §4.1): one account for every store.
 *
 * What never changes: the email (a customer who needs another one registers again), the account
 * type, and the home store — until the account is anonymized, when the email is replaced by a
 * placeholder and the old address is free again (spec §1.10). What only moves forward: a verified
 * email is never unverified again, and once a phone is verified the account always has one.
 * Blocking stops signing in; it is a separate column from the deletion, so a blocked account's
 * deletion still runs (spec §4.1).
 */
final class Customer
{
    /** What an anonymized account is called wherever a name is still shown (handoff §7.9). */
    public const string DELETED_NAME = 'Deleted customer';

    /** @var list<string> */
    private array $changed = [];

    private function __construct(
        private readonly string $id,
        // Not readonly for one reason: anonymizing replaces it with a placeholder (spec §1.10).
        private EmailAddress $email,
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
        private ?DateTimeImmutable $anonymizedAt = null,
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
        ?DateTimeImmutable $anonymizedAt = null,
        int $sessionVersion = 0,
    ): self {
        if ($sessionVersion < 0) {
            throw new InvalidAccessAttribute('session_version', 'zero or more');
        }

        return new self(
            $id, $email, $passwordHash, $firstName, $lastName, $accountType, $status,
            $emailVerifiedAt, $phone, $phoneVerifiedAt, $language, $homeStoreId, $lastStoreId,
            $termsVersion, $termsAcceptedAt, $deletionScheduledFor, $anonymizedAt, $sessionVersion,
        );
    }

    /**
     * A new password — changed by its owner or reset by email link. Every session signed in before
     * it ends (spec §1.8): the session version moves on.
     */
    public function changePassword(string $passwordHash): void
    {
        $this->refuseWhenAnonymized();
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
     */
    public function mayOrder(): bool
    {
        return $this->status === CustomerStatus::Active
            && $this->emailVerifiedAt !== null
            && $this->phoneVerifiedAt !== null
            && $this->deletionScheduledFor === null
            && $this->anonymizedAt === null;
    }

    /**
     * The customer asked for their account to be deleted (spec §1.10): it is locked for ordering
     * at once, and anonymized on that date unless they sign in first.
     */
    public function scheduleDeletion(DateTimeImmutable $on): void
    {
        $this->refuseWhenAnonymized();

        if ($this->deletionScheduledFor !== null) {
            return;
        }

        $this->deletionScheduledFor = $on;
        $this->markChanged('deletion_scheduled_for');
    }

    /**
     * They signed in, or asked for it to stop (spec §1.10, amendment 43).
     */
    public function cancelDeletion(): void
    {
        $this->refuseWhenAnonymized();

        if ($this->deletionScheduledFor === null) {
            return;
        }

        $this->deletionScheduledFor = null;
        $this->markChanged('deletion_scheduled_for');
    }

    /**
     * Staff block an account (spec §1.1, §3.3): every session of theirs ends on its next request,
     * and only the right password is told that the account is blocked (spec §1.8).
     *
     * @throws InvalidCustomerStatus when it is already blocked
     */
    public function block(): void
    {
        $this->refuseWhenAnonymized();

        if ($this->status === CustomerStatus::Blocked) {
            throw new InvalidCustomerStatus('blocked', 'already blocked');
        }

        $this->status = CustomerStatus::Blocked;
        $this->markChanged('status');
    }

    /**
     * @throws InvalidCustomerStatus when it is not blocked
     */
    public function unblock(): void
    {
        $this->refuseWhenAnonymized();

        if ($this->status === CustomerStatus::Active) {
            throw new InvalidCustomerStatus('unblocked', 'not blocked');
        }

        $this->status = CustomerStatus::Active;
        $this->markChanged('status');
    }

    /**
     * The deletion runs (spec §1.10, amendment 43): nothing that names a person is left, the
     * account can never sign in again, and its old email is free for a new account. The id, the
     * account type, the home store and the dates stay, so counts and other modules' keys hold.
     *
     * @param  EmailAddress  $email  the placeholder that takes the old address's place
     * @param  string  $passwordHash  a value no password can match
     *
     * @throws InvalidCustomerStatus when it was anonymized already
     */
    public function anonymize(EmailAddress $email, string $passwordHash, DateTimeImmutable $at): void
    {
        $this->refuseWhenAnonymized();

        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->firstName = self::DELETED_NAME;
        $this->lastName = self::DELETED_NAME;
        $this->emailVerifiedAt = null;
        $this->phone = null;
        $this->phoneVerifiedAt = null;
        $this->deletionScheduledFor = null;
        $this->anonymizedAt = $at;
        // Every session of theirs ends at its next request, whatever it holds.
        $this->sessionVersion++;
        $this->markChanged('anonymized');
    }

    public function isAnonymized(): bool
    {
        return $this->anonymizedAt !== null;
    }

    public function anonymizedAt(): ?DateTimeImmutable
    {
        return $this->anonymizedAt;
    }

    /**
     * @throws InvalidCustomerStatus
     */
    private function refuseWhenAnonymized(): void
    {
        if ($this->anonymizedAt !== null) {
            throw new InvalidCustomerStatus('changed', 'the account was deleted');
        }
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
     * Set while a deletion is pending (spec §1.10): they cannot order meanwhile.
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
