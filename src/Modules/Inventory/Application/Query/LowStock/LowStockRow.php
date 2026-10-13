<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\LowStock;

/**
 * One low size: its product's names (a draft may have its Arabic name only), its code - for staff, never
 * a shopper - and its figures: in stock, held, what can be ordered or given now, and the threshold.
 */
final readonly class LowStockRow
{
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $productNameAr,
        public ?string $productNameEn,
        public string $code,
        public int $inStock,
        public int $held,
        public int $available,
        public int $threshold,
    ) {}
}
