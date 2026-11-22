<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * Sales working out a staff edit before shipping (loyalty.md §1.8, §2.2): the edited order's
 * `net_subtotal`, and the room the coupon left under the discount ceiling.
 */
final readonly class EditedPointsRequest
{
    public function __construct(
        public string $orderId,
        public string $storeId,
        public Money $editedNetSubtotal,
        public Money $room,
    ) {}
}
