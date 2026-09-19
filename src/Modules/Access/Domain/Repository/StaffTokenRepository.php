<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use DateTimeImmutable;
use Modules\Access\Domain\Model\PhoneCode;
use Modules\Access\Domain\Model\StaffEmailChange;
use Modules\Access\Domain\Model\StaffInvitation;
use Modules\Access\Domain\ValueObject\EmailAddress;

/**
 * The links and codes of staff accounts: invitations, email changes and phone codes. Only hashes
 * are stored. Each staff member has at most one of each; a new one replaces the old.
 */
interface StaffTokenRepository
{
    public function putInvitation(string $staffId, string $tokenHash, DateTimeImmutable $expiresAt, ?string $invitedBy): void;

    /**
     * Locks it.
     */
    public function invitationByToken(string $tokenHash): ?StaffInvitation;

    public function setPendingPassword(string $staffId, string $passwordHash): void;

    public function deleteInvitation(string $staffId): void;

    public function putEmailChange(string $staffId, EmailAddress $newEmail, string $tokenHash, DateTimeImmutable $expiresAt, ?string $requestedBy): void;

    /**
     * Locks it.
     */
    public function emailChangeByToken(string $tokenHash): ?StaffEmailChange;

    public function deleteEmailChange(string $staffId): void;

    public function putPhoneCode(PhoneCode $code): void;

    /**
     * Locks it.
     */
    public function phoneCode(string $staffId): ?PhoneCode;

    public function countFailedAttempt(string $staffId): void;

    public function deletePhoneCode(string $staffId): void;
}
