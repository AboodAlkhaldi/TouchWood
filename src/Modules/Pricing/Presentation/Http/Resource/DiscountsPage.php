<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **Category Discounts** (pricing.md §4.3.3 P3).
 */
#[TypeScript]
final class DiscountsPage extends Data
{
    /**
     * @param  list<DiscountRowData>  $discounts
     */
    public function __construct(
        public PricingFrameData $storeFrame,
        public string $storeTimezone,
        public string $state,
        public string $sort,
        public array $discounts,
        public ?string $next,
        public int $total,
    ) {}
}
