<?php

declare(strict_types=1);

namespace Modules\Access\Application\Customer;

use DateTimeImmutable;

/**
 * The links Access sends a customer. The web layer builds them, so the Application layer never
 * knows about routes (spec §5.2: the verification link is a signed URL and needs no table).
 */
interface CustomerLinks
{
    /**
     * A link that verifies this email address until it expires, in the store the account belongs
     * to and in the customer's language.
     */
    public function emailVerification(string $customerId, string $storeCode, string $locale, DateTimeImmutable $expiresAt): string;

    /**
     * The page where a customer chooses a new password, in the store they asked from. The token is
     * random and stored only as a hash, so this link needs no signature.
     */
    public function passwordReset(string $token, string $storeCode, string $locale): string;
}
