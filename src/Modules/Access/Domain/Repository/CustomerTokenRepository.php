<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use DateTimeImmutable;
use Modules\Access\Domain\Model\CustomerPasswordReset;
use Modules\Access\Domain\Model\CustomerPhoneCode;

/**
 * A customer's SMS codes. Only hashes are stored, and each customer has at most one live code: a
 * new request replaces it (spec §4.2). The email verification link needs no table — it is a signed
 * URL (spec §5.2).
 */
interface CustomerTokenRepository
{
    public function putPhoneCode(CustomerPhoneCode $code): void;

    /**
     * The customer's live code, whatever its purpose. Locks it.
     */
    public function phoneCode(string $customerId): ?CustomerPhoneCode;

    public function countFailedAttempt(string $customerId): void;

    public function deletePhoneCode(string $customerId): void;

    public function putPasswordReset(string $customerId, string $tokenHash, DateTimeImmutable $expiresAt): void;

    /**
     * The live reset link with this hash. Locks it, so it is used once.
     */
    public function passwordResetByToken(string $tokenHash): ?CustomerPasswordReset;

    public function deletePasswordReset(string $customerId): void;
}
