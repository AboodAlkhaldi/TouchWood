<?php

declare(strict_types=1);

namespace Modules\Pricing\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **The offer dialog's note** (`OverlapsFor`, P2): each size with the offer as typed, and what it
 * overlaps.
 */
#[TypeScript]
final class OverlapNoteData extends Data
{
    /**
     * @param  list<OverlapOfferData>  $sales
     * @param  list<OverlapOfferData>  $discounts
     * @param  list<OverlapSizeData>  $sizes
     */
    public function __construct(
        public array $sales,
        public array $discounts,
        public array $sizes,
        public bool $atStart,
        public ?int $ceilingPercent,
    ) {}
}
