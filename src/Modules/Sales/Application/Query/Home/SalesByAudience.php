<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Home;

use Shared\Domain\ValueObject\Money;

/**
 * **Sales by who ordered** (`SalesHomeReads::byAudience`; dashboard.md §2 D, §3.1): `revenueByDay`'s revenue
 * over the same orders - placed in the range, never cancelled, rejected or come back, never a test order -
 * individuals' and companies' apart, in the store's currency.
 */
final readonly class SalesByAudience
{
    public function __construct(
        public Money $individuals,
        public Money $companies,
    ) {}
}
