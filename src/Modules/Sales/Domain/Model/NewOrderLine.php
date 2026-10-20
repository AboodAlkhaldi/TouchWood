<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

/**
 * A line of an order being placed, snapshotted (sales.md §1.3 step 7, §5 `order_lines`): the product's
 * names and the size's values in both languages and its code as they are now, the price and its kind,
 * and its shares of the coupon, the points and the VAT. The gift's line is free, its kind none.
 * Amounts in the order's currency's smallest unit.
 */
final readonly class NewOrderLine
{
    /**
     * @param  list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>  $variantValues
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $variantId,
        public bool $isGift,
        public string $nameAr,
        public string $nameEn,
        public array $variantValues,
        public string $code,
        public string $saleMode,
        public ?string $priceKind,
        public int $quantity,
        public int $unit,
        public int $listUnit,
        public int $total,
        public int $couponShare,
        public int $pointsShare,
        public int $vatShare,
    ) {}
}
