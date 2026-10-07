<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A variant's value of one attribute of its variation.
 */
#[TypeScript]
final class VariantValueData extends Data
{
    public function __construct(
        public string $attributeId,
        public string $valueId,
        public string $nameAr,
        public string $nameEn,
        public ?string $swatch,
    ) {}
}
