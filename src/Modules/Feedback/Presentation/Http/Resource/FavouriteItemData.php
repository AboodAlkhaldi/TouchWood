<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A product of Favourited Products (feedback.md §4.1 F3): how many customers saved it in the store. */
#[TypeScript]
final class FavouriteItemData extends Data
{
    public function __construct(
        public ProductCardData $product,
        public int $savedBy,
    ) {}
}
