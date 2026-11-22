<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **Wholesale Prices** (pricing.md §4.3.3 P4): the sizes with bands in the store.
 */
#[TypeScript]
final class WholesalePage extends Data
{
    /**
     * @param  list<WholesaleRowData>  $rows
     */
    public function __construct(
        public PricingFrameData $storeFrame,
        public string $storeTimezone,
        public string $search,
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
