<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;

/**
 * An open invitation. Only the link's hash is stored; the password the invitee chose waits here,
 * hashed, until their phone's code is right.
 */
final readonly class StaffInvitation
{
    public function __construct(
        public string $staffId,
        public DateTimeImmutable $expiresAt,
        public ?string $pendingPasswordHash,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
