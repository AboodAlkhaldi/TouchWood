<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

use Shared\Domain\ValueObject\Money;

/**
 * A line of an order as it was sold (sales.md §1.3 step 7, §1.7): its snapshot — names, the size's values, the
 * code, the mode — the pieces and the prices; a part of a size priced on its own, or the gift. `lineId` is for
 * acting on it (asking a return), never shown.
 */
final readonly class OrderLineView
{
    /**
     * @param  list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>  $values
     */
    public function __construct(
        public string $lineId,
        public string $productId,
        public string $variantId,
        public string $nameAr,
        public string $nameEn,
        public array $values,
        public string $code,
        public string $mode,
        public bool $gift,
        public int $quantity,
        public Money $unit,
        public Money $listUnit,
        public Money $total,
        /** How many pieces may still be asked back now; 0 outside the return window. */
        public int $returnable,
    ) {}
}
