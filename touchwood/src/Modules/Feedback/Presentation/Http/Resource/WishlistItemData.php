<?php

declare(strict_types=1);

namespace Modules\Feedback\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A product of a customer's wishlist, and when it was saved; `product.sold` false reads "Not sold now". */
#[TypeScript]
final class WishlistItemData extends Data
{
    public function __construct(
        public ProductCardData $product,
        public string $savedAt,
    ) {}
}
