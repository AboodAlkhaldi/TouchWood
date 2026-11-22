<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A filter attribute on a category's page and its values present there (P14).
 */
#[TypeScript]
final class ShopFilterGroupData extends Data
{
    /**
     * @param  list<ShopFilterChoiceData>  $choices
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $choices,
    ) {}
}
