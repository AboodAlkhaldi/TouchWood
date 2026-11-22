<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PriceViews;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * **A size's retail price as kept** (pricing.md §4.3.3 P1 "Retail Price"): the amount already written,
 * its source - `STAFF` (Set Here), `FILE` (From File), `PROVIDER` (From Provider) - and when it last
 * changed (ISO 8601).
 */
final readonly class RetailPriceView
{
    public function __construct(
        public MoneyData $price,
        public string $source,
        public string $updatedAt,
    ) {}
}
