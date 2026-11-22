<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

use DateTimeImmutable;

/**
 * A placed order's fact, with or without a coupon (promotions.md §1.5, §2.2): what the segments count.
 * Promotions adds the store's ceiling of that moment (§1.6). Not named `OrderPlaced`: that is Sales's
 * integration event (handoff §4.5).
 */
final readonly class RecordOrder
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public string $storeId,
        public DateTimeImmutable $placedAt,
        public CallerPermission $caller,
    ) {}
}
