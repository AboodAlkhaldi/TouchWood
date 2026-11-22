<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **Needs a Price** (pricing.md §4.3.3 P5): the sizes switched on with no price, 50 at a time with the
 * total.
 */
#[TypeScript]
final class NeedsAPricePage extends Data
{
    /**
     * @param  list<SizeData>  $rows
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
