<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One mode of a size on Add to Cart (sales.md §4.2 B15): how many pieces its cart line may hold (`maximum`
 * null when there is no most), how many it holds now — an add joins that line, so the line's limits are
 * on the two together — and whether too few are free for the least that would leave the line within its
 * limits, the pieces of the size already in the cart in either mode counted first, as the cart counts them.
 */
#[TypeScript]
final class ShopCartLimitsData extends Data
{
    public function __construct(
        public int $minimum,
        public ?int $maximum,
        public int $inCart,
        public bool $outOfStock,
    ) {}
}
