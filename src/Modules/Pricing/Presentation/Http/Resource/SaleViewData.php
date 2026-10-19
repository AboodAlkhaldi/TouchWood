<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **View Offer** (P2): one offer, every field, and its sizes.
 */
#[TypeScript]
final class SaleViewData extends Data
{
    /**
     * @param  list<SaleSizeData>  $sizes
     */
    public function __construct(
        public SaleRowData $sale,
        public array $sizes,
    ) {}
}
