<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use Modules\Catalog\Public\Enums\SaleMode;

/**
 * Which lines a category discount applies to (pricing.md §1.5): retail lines, wholesale lines, or both.
 */
enum DiscountLines: string
{
    case Retail = 'RETAIL';
    case Wholesale = 'WHOLESALE';
    case Both = 'BOTH';

    public function covers(SaleMode $mode): bool
    {
        return $this === self::Both || $this->value === $mode->value;
    }

    /** Whether two discounts can price the same line: one of them on both, or the same lines. */
    public function meets(self $other): bool
    {
        return $this === self::Both || $other === self::Both || $this === $other;
    }
}
