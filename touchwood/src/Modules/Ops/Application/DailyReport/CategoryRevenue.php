<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use Shared\Domain\ValueObject\Money;

/**
 * Revenue without VAT of one category's products, net of the refunds of the returns asked in the period (ops.md
 * §1.11 item 4; owner, 2026-11-01); a product with no category is null.
 */
final readonly class CategoryRevenue
{
    public function __construct(
        public ?string $categoryId,
        /** The path from the root, "Doors › Interior", in each language; empty for no category. */
        public string $nameAr,
        public string $nameEn,
        /** The pieces sold in the period: Sales gives no refunded pieces by product. */
        public int $units,
        public Money $revenue,
    ) {}
}
