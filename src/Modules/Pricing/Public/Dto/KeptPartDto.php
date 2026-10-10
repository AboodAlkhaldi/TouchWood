<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;

/**
 * Pieces already on an order that staff are editing before it ships (pricing.md §1.11): they keep the
 * prices they were sold at (owner, 2026-10-10). Taken from the order's snapshot; the quantity is the
 * edited one - lowered when staff took pieces off, never raised (added pieces are a `CartLineDto`).
 */
final readonly class KeptPartDto
{
    public function __construct(
        public string $variantId,
        public SaleMode $mode,
        public int $quantity,
        public Money $listUnit,
        public Money $unit,
        public PriceKind $kind,
    ) {}
}
