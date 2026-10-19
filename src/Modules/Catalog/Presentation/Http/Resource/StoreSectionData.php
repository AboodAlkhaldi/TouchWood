<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One module's part of a store card on a product's Stores tab (catalog.md amendment 23(e)): the module,
 * the component under `resources/js/sections/` that draws it, and its data for this store - plain data
 * the module wrote, which Catalog carries without reading.
 */
#[TypeScript]
final class StoreSectionData extends Data
{
    /**
     * @param  string  $component  its path under resources/js/sections/, e.g. "Pricing/ProductPrices"
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $module,
        public string $component,
        public array $data,
    ) {}
}
