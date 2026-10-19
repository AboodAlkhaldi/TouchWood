<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PriceViews;

use Modules\Platform\Public\Dto\MoneyData;

/**
 * One wholesale band as the screens show it (pricing.md §4.3.3 P4): "From 10: 95.00".
 */
final readonly class BandView
{
    public function __construct(
        public int $fromQuantity,
        public MoneyData $price,
    ) {}
}
