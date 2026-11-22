<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An active label a store may put on a product (catalog.md §1.8, §4.4 S9 "Stores"), drawn in its tone.
 */
#[TypeScript]
final class LabelChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public string $tone,
        public bool $active,
    ) {}
}
