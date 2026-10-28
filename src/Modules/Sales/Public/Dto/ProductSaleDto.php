<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** One size's sales over a range (`SalesReports::productSales`). */
final readonly class ProductSaleDto
{
    public function __construct(
        public string $variantId,
        public string $productId,
        public int $units,
        /** Its lines' totals less their coupon and points shares — without VAT, without shipping. */
        public Money $revenue,
    ) {}
}
