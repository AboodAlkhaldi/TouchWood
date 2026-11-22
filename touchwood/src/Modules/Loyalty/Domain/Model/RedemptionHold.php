<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model;

use DateTimeImmutable;

/** Points held while their customer pays online, under the order's future id (loyalty.md §9.6). */
final readonly class RedemptionHold
{
    public function __construct(
        public string $orderId,
        public string $accountId,
        public string $storeId,
        public int $points,
        public int $discountMinor,
        public string $currencyCode,
        public int $capPercent,
        public DateTimeImmutable $reservedAt,
    ) {}
}
