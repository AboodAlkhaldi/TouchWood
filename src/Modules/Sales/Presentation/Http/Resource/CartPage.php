<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * S4 - the cart (sales.md §4.1): its lines in the order added, whether this account may check out at all,
 * the coupon typed, the lines that left at a sign-in, and the subtotal of the lines that can be ordered -
 * before the coupon, points, shipping and VAT, which checkout works out.
 */
#[TypeScript]
final class CartPage extends Data
{
    /**
     * @param  list<CartLineData>  $lines
     * @param  list<CartNoticeData>  $notice
     * @param  list<array<string, mixed>>  $skipped
     */
    public function __construct(
        public array $lines,
        public bool $mayOrder,
        public ?string $couponCode,
        public array $notice,
        public ?MoneyData $subtotal,
        /** Every piece in the cart, each line's. */
        public int $pieces,
        /** A line with a problem: Checkout waits until it is fixed (R2-11). */
        public bool $problems,
        /** Buy Again's lines that could not go in, and why, said once (R2-24): BuyAgainSkippedData each. */
        public array $skipped,
    ) {}
}
