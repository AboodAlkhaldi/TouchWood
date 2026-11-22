<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;

/**
 * A password reset link waiting to be used (spec §1.8, amendment 31: 30 minutes).
 */
final readonly class StaffPasswordReset
{
    public function __construct(
        public string $staffId,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
