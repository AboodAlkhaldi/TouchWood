<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * X1 · Shipping › Boxes (shipping.md §4.1 part 3): the padding, the boxes in their order, and the first page of
 * the sizes packed by hand - filtered by `filter` when one is typed (B12).
 */
#[TypeScript]
final class BoxesPage extends Data
{
    /**
     * @param  list<BoxData>  $boxes
     * @param  list<StoreOptionData>  $stores
     */
    public function __construct(
        public int $paddingMm,
        public array $boxes,
        public PackingSizesData $handPacked,
        public ?string $filter,
        public string $storeCode,
        public string $storeName,
        public bool $storeIsActive,
        public array $stores,
    ) {}
}
