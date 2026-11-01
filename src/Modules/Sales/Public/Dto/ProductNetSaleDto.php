<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * One product's sales over a range, net of its refunds (`SalesReports::productNetSales`; ops.md §1.10, the
 * owner's rules of 2026-10-28 and 2026-10-30): the goods without VAT and shipping, as `revenueByDay`.
 */
final readonly class ProductNetSaleDto
{
    public function __construct(
        public string $productId,
        /** Pieces sold in the orders placed in the range that count; the gift never. */
        public int $units,
        /** Those lines' totals less their coupon and points shares. */
        public Money $revenue,
        /** The goods refunded by returns asked in the range and completed — its share of `revenueByDay`'s `refunds`. */
        public Money $refunds,
        /** The goods the returns asked in the range and not finished would refund, estimated. */
        public Money $waitingRefunds,
    ) {}
}
