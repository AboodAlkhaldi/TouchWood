<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

/**
 * A line of a placed order as it stands (sales.md §1.7, §5): a part of a size and mode at one price —
 * placed with the order, or added by an edit (`editId`) — or the gift. Amounts in the order's currency's
 * smallest unit.
 */
final readonly class OrderLine
{
    /**
     * @param  list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>  $variantValues
     */
    public function __construct(
        public string $id,
        public string $productId,
        public string $variantId,
        public ?string $editId,
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
        public bool $countsOnStock,
    ) {}
}
