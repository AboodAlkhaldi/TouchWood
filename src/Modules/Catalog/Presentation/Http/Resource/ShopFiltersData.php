<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A category page's filters and sort (P14, P15): brands, the filter attributes' values, Retail and
 * Wholesale for a company or a guest.
 */
#[TypeScript]
final class ShopFiltersData extends Data
{
    /**
     * @param  list<ShopFilterChoiceData>  $brands
     * @param  list<ShopFilterGroupData>  $attributes
     * @param  list<ShopFilterChoiceData>  $sides  none for an individual
     */
    public function __construct(
        public array $brands,
        public array $attributes,
        public array $sides,
        public string $sort,
    ) {}
}
