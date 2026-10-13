<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * A staff edit before shipping that lowered the points discount (loyalty.md §1.8, §2.2): the order's
 * new points discount, never above the old; keyed by the edit's id, so it counts once.
 */
final readonly class SettleEdit
{
    public function __construct(
        public string $orderId,
        public string $storeId,
        public string $editId,
        public Money $newDiscount,
        public CallerPermission $caller,
    ) {}
}
