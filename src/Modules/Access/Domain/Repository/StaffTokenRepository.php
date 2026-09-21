<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use DateTimeImmutable;
use Modules\Access\Domain\Model\PhoneCode;
use Modules\Access\Domain\Model\StaffEmailChange;
use Modules\Access\Domain\Model\StaffInvitation;
use Modules\Access\Domain\Model\StaffPasswordReset;
use Modules\Access\Domain\Model\TrustedBrowser;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;

/**
 * The links, codes and trusted browsers of staff accounts. Only hashes are stored. Each staff
 * member has at most one link and one code of each kind; a new one replaces the old. Sign-in codes
 * are kept apart from the codes that verify a phone, so signing in never ends a phone change.
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
     * The live code for this purpose's kind — signing in, or verifying a phone. Locks it.
     */
    public function phoneCode(string $staffId, PhoneCodePurpose $purpose): ?PhoneCode;

    public function countFailedAttempt(string $staffId, PhoneCodePurpose $purpose): void;

    /**
     * @param  PhoneCodePurpose|null  $purpose  null: every code, signing in included
     */
    public function deletePhoneCode(string $staffId, ?PhoneCodePurpose $purpose = null): void;

    public function putPasswordReset(string $staffId, string $tokenHash, DateTimeImmutable $expiresAt): void;

    /**
     * Locks it.
     */
    public function passwordResetByToken(string $tokenHash): ?StaffPasswordReset;

    public function deletePasswordReset(string $staffId): void;

    public function addTrustedBrowser(TrustedBrowser $browser, string $tokenHash): void;

    public function trustedBrowser(string $tokenHash): ?TrustedBrowser;

    public function touchTrustedBrowser(string $id, DateTimeImmutable $usedAt): void;

    /**
     * Every browser this staff member trusted is forgotten (spec §1.8).
     */
    public function forgetTrustedBrowsers(string $staffId): void;
}
