<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use Modules\Catalog\Public\Enums\SaleMode;

/**
 * One line to price (pricing.md §2.2): a variant, the way it is bought, how many. Quantity prices
 * apply to wholesale lines only (owner, 2026-10-07); who may buy wholesale is Sales's to check.
 */
final readonly class CartLineDto
{
    public function __construct(
        public string $variantId,
        public SaleMode $mode,
        public int $quantity,
    ) {}
}
