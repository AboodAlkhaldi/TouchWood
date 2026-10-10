<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An active variation a product may make its variants from, its attributes in order (catalog.md
 * §4.4 S9).
 */
#[TypeScript]
final class VariationOptionData extends Data
{
    /**
     * @param  list<string>  $attributeIds
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public array $attributeIds,
    ) {}
}
