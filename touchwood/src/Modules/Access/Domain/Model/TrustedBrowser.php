<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;

/**
 * A browser where a staff member signs in with the password alone until the trust expires (spec
 * §1.8): its cookie holds a random token, stored here only as a hash.
 */
final readonly class TrustedBrowser
{
    public function __construct(
        public string $id,
        public string $staffId,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
