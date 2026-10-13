<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * A return completed (loyalty.md §1.8, §2.2): the returned goods' allocated share of `goods_total` and
 * the returned share of the points discount. Once the order is returned in full, the shares Sales has
 * passed add up exactly to both, so the last return lands on the order's points.
 */
final readonly class SettleReturn
{
    public function __construct(
        public string $orderId,
        public string $storeId,
        public string $returnId,
        public Money $goodsShare,
        public Money $discountShare,
        public CallerPermission $caller,
    ) {}
}
