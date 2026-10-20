<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A size the gift picker found (promotions.md §4.1 M4): its product's name in the page's language, its
 * code, and its photo's thumbnail when it has one.
 */
#[TypeScript]
final class SizeChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $productName,
        public string $code,
        public ?string $photo,
    ) {}
}
