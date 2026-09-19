<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;
use Modules\Access\Domain\ValueObject\PhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;

/**
 * The one live SMS code of a staff member (spec §4.2): a new request replaces it. Only its hash is
 * stored.
 */
final readonly class PhoneCode
{
    public function __construct(
        public string $staffId,
        public PhoneCodePurpose $purpose,
        public PhoneNumber $phone,
        public string $codeHash,
        public int $attempts,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $sentAt,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
