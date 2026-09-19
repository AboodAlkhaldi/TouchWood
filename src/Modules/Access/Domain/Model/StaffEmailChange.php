<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;
use Modules\Access\Domain\ValueObject\EmailAddress;

/**
 * A new email waiting for its link to be used (amendment 17).
 */
final readonly class StaffEmailChange
{
    public function __construct(
        public string $staffId,
        public EmailAddress $newEmail,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
