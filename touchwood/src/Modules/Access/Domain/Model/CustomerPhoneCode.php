<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;
use Modules\Access\Domain\ValueObject\CustomerPhoneCodePurpose;
use Modules\Access\Domain\ValueObject\PhoneNumber;

/**
 * The one live SMS code of a customer (spec §4.2): a new request replaces it. Only its hash is
 * stored, and the number it went to is kept with it — a change takes effect only when it verifies.
 */
final readonly class CustomerPhoneCode
{
    public function __construct(
        public string $customerId,
        public CustomerPhoneCodePurpose $purpose,
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
