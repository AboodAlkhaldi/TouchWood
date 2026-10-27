<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Sales's part of a product's page in the shop, `sales.cart` (sales.md §4.2 B15): Add to Cart for each size the page shows, in its order. */
#[TypeScript]
final class ShopCartData extends Data
{
    /**
     * @param  list<ShopCartSizeData>  $sizes
     */
    public function __construct(
        public array $sizes,
    ) {}
}
