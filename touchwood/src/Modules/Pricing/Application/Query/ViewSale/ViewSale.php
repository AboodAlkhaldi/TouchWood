<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSale;

/**
 * **One sale, every field** (pricing.md §4.3.3 P2 "View Sale", and Sync's link to a sale).
 */
final readonly class ViewSale
{
    public function __construct(
        public string $saleId,
    ) {}
}
