<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What this viewer may add to the cart of one size (sales.md §4.2 B15): the limits of each mode they may
 * buy it in — null for a mode the size is not sold in now, or not to them (an individual buys retail
 * only) — whether too few are free to add even the least a line may hold, and the pieces of it already
 * in their cart, over both modes.
 */
#[TypeScript]
final class ShopCartSizeData extends Data
{
    public function __construct(
        public string $variantId,
        public ?ShopCartLimitsData $retail,
        public ?ShopCartLimitsData $wholesale,
        public bool $outOfStock,
        public int $inCart,
    ) {}
}
