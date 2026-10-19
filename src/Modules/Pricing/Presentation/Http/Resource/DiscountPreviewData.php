<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **A discount's preview** (P3): its totals, its products 20 at a time, what it leaves out and overlaps.
 */
#[TypeScript]
final class DiscountPreviewData extends Data
{
    /**
     * @param  list<PreviewProductData>  $reached
     * @param  list<PreviewProductData>  $leftOut
     * @param  list<OverlapOfferData>  $overlappingSales
     * @param  list<OverlapOfferData>  $overlappingDiscounts
     */
    public function __construct(
        public array $reached,
        public ?string $next,
        public int $reachedProducts,
        public int $reachedSizes,
        public int $skippedSizes,
        public int $unpricedSizes,
        public ?int $deepestBasisPoints,
        public array $leftOut,
        public array $overlappingSales,
        public array $overlappingDiscounts,
    ) {}
}
