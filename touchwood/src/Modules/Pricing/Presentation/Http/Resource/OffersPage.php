<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **Offers** (pricing.md §4.3.3 P2): the store's offers in the state and order asked, of one product or
 * the one a link names.
 */
#[TypeScript]
final class OffersPage extends Data
{
    /**
     * @param  list<SaleRowData>  $sales
     */
    public function __construct(
        public PricingFrameData $storeFrame,
        public string $storeTimezone,
        public string $state,
        public string $sort,
        public ?PickedProductData $product,
        public ?string $saleId,
        public array $sales,
        public ?string $next,
        public int $total,
    ) {}
}
