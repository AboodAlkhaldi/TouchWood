<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

/**
 * An order's totals after a staff edit (sales.md §1.7): what it is worth now, with its coupon and points as
 * the edit left them, its shipping (re-quoted when the address moved — §9.5 #12), its gift and — when it changed — its address. Amounts in its currency's smallest unit.
 */
final readonly class EditedTotals
{
    /**
     * @param  array<string, mixed>|null  $address  the new address's snapshot; null when it stays
     */
    public function __construct(
        public int $couponDiscount,
        public int $pointsUsed,
        public int $pointsDiscount,
        public int $grossSubtotal,
        public int $netSubtotal,
        public int $goodsTotal,
        public OrderShipping $shipping,
        public int $taxableBase,
        public int $vat,
        public int $orderTotal,
        public ?string $giftLevelId,
        public ?array $address,
    ) {}
}
