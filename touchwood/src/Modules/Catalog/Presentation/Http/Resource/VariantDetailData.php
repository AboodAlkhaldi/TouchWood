<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A variant's detail of one "details only" attribute: text in both languages, or a number.
 */
#[TypeScript]
final class VariantDetailData extends Data
{
    public function __construct(
        public string $attributeId,
        public ?string $textAr,
        public ?string $textEn,
        public ?string $number,
    ) {}
}
