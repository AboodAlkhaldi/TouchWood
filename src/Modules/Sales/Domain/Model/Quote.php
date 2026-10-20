<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

use DateTimeImmutable;

/**
 * A checkout's quote as kept (sales.md §1.2, §5): what was asked, and what it came to — `result`, the
 * itemised answer, never changed — valid until `expiresAt`.
 */
final readonly class Quote
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $customerId,
        public string $addressId,
        public ?string $couponCode,
        public bool $usePoints,
        public ?int $pointsAsked,
        public string $wayToPay,
        public array $result,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
    ) {}
}
