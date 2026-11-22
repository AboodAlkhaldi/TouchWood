<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An attribute with its values, as a product's forms offer it (catalog.md §4.4 S9).
 */
#[TypeScript]
final class AttributeChoiceData extends Data
{
    /**
     * @param  list<ChoiceValueData>  $values
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public string $kind,
        public ?string $unitAr,
        public ?string $unitEn,
        public bool $isColour,
        public bool $active,
        public array $values,
    ) {}
}
