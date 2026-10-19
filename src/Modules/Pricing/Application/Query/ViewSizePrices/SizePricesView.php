<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSizePrices;

use Modules\Pricing\Application\Query\PriceViews\BandView;
use Modules\Pricing\Application\Query\PriceViews\RetailPriceView;
use Modules\Pricing\Application\Query\PriceViews\SizeView;
use Modules\Pricing\Application\Query\PriceViews\TodayPrice;

/**
 * **A size's drawer** (pricing.md §4.3.3, Q3): the size; its retail price with its source and when; what
 * one piece costs today on a retail line and, when the store sells it wholesale, what a wholesale line of
 * its minimum (`wholesaleQuantity`) costs - each with why; every sale and category discount reaching it,
 * running or scheduled; its bands, the product's wholesale minimum and maximum there, and whether the
 * minimum has moved from the first band since (§1.4: **Minimum Changed**).
 */
final readonly class SizePricesView
{
    /**
     * @param  list<ReachingSale>  $sales
     * @param  list<ReachingDiscount>  $discounts
     * @param  list<BandView>  $bands
     */
    public function __construct(
        public SizeView $size,
        public ?RetailPriceView $retail,
        public ?TodayPrice $todayRetail,
        public ?TodayPrice $todayWholesale,
        public ?int $wholesaleQuantity,
        public bool $soldRetail,
        public bool $soldWholesale,
        public array $sales,
        public array $discounts,
        public array $bands,
        public ?int $wholesaleMinimum,
        public ?int $wholesaleMaximum,
        public bool $minimumChanged,
    ) {}
}
