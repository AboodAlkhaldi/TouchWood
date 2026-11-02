<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** What `SalesReports::productSales` adds up to over a range: the same units and revenue, in one figure each. */
final readonly class ProductSalesTotalsDto
{
    public function __construct(
        public int $units,
        public Money $revenue,
    ) {}
}
