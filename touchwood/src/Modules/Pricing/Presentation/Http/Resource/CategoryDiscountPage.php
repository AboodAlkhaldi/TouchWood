<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **A category discount's page** (pricing.md §4.3.3 P3): its fields and its preview as of today.
 */
#[TypeScript]
final class CategoryDiscountPage extends Data
{
    public function __construct(
        public PricingFrameData $storeFrame,
        public string $storeTimezone,
        public DiscountRowData $discount,
        public DiscountPreviewData $preview,
    ) {}
}
