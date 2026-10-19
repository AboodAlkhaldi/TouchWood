<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **Prices** (pricing.md §4.3.3 P1): the store's sizes and their prices, 50 at a time with the total, the
 * search and the filter applied.
 */
#[TypeScript]
final class PricesPage extends Data
{
    /**
     * @param  list<PriceRowData>  $rows
     */
    public function __construct(
        public PricingFrameData $storeFrame,
        public string $storeTimezone,
        public string $search,
        public string $filter,
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
