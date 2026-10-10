<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A value an attribute offers a product's forms; an inactive one only where it is already held.
 */
#[TypeScript]
final class ChoiceValueData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public ?string $swatch,
        public bool $active,
    ) {}
}
