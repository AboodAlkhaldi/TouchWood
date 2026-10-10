<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The variations screen (catalog.md §4.4 S4): the sets, and the attributes they are made of — to name
 * a set's members and to pick new ones from the active variant-making ones.
 */
#[TypeScript]
final class VariationsPage extends Data
{
    /**
     * @param  list<VariationData>  $variations
     * @param  list<AttributeData>  $attributes
     */
    public function __construct(
        public array $variations,
        public array $attributes,
        public bool $mayChange,
    ) {}
}
