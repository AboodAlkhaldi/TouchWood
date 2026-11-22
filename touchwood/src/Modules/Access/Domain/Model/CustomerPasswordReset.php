<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;

/**
 * A customer's password reset link waiting to be used (spec §1.8: 60 minutes).
 */
final readonly class CustomerPasswordReset
{
    public function __construct(
        public string $customerId,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
