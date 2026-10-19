<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of the bought size's values: "Width: 60 cm" (feedback.md §1.1). */
#[TypeScript]
final class ShopVariantValueData extends Data
{
    public function __construct(
        public string $attribute,
        public string $value,
    ) {}
}
