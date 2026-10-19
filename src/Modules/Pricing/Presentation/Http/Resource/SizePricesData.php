<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **A size's drawer** (pricing.md §4.3.3 P1, Q3): its retail price, what one piece costs today retail and
 * wholesale and why, and every offer, discount and band reaching it.
 */
#[TypeScript]
final class SizePricesData extends Data
{
    /**
     * @param  list<ReachingSaleData>  $sales
     * @param  list<ReachingDiscountData>  $discounts
     * @param  list<BandData>  $bands
     */
    public function __construct(
        public SizeData $size,
        public ?RetailPriceData $retail,
        public ?TodayPriceData $todayRetail,
        public ?TodayPriceData $todayWholesale,
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
