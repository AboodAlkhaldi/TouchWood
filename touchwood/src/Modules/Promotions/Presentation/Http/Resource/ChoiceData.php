<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Something a coupon's rule picker offers (promotions.md §4.1 M2; owner's Q4: read and picked by name):
 * a category with its path ("Kitchens › Drawers"), a brand, or a product with its code.
 */
#[TypeScript]
final class ChoiceData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $detail,
    ) {}
}
