<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of a product page's choices (catalog.md §4.5): a variant attribute, in the product's
 * order, and its values in the attribute's.
 */
#[TypeScript]
final class ShopChoiceData extends Data
{
    /**
     * @param  list<ShopChoiceValueData>  $values
     */
    public function __construct(
        public string $id,
        public string $name,
        public bool $isColour,
        public array $values,
    ) {}
}
