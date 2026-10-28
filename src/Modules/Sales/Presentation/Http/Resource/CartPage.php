<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Http\Resource;

use Modules\Platform\Public\Dto\MoneyData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * S4 - the cart (sales.md §4.1): its lines in the order added, whether this account may check out at all,
 * the coupon typed, the lines that left at a sign-in, and the subtotal of the lines that can be ordered -
 * before the coupon, points, shipping and VAT, which checkout works out. Its extras (§4.2 B12, B14): why this
 * account may not order yet, the shipping and how much more ships free, the next gift, the points to earn, and
 * the store's best sellers under an empty cart.
 */
#[TypeScript]
final class CartPage extends Data
{
    /**
     * @param  list<CartLineData>  $lines
     * @param  list<CartNoticeData>  $notice
     * @param  list<array<string, mixed>>  $skipped
     * @param  list<array<string, mixed>>  $bestSellers  Catalog's ShopCardData each
     * @param  array<string, array<string, array<string, mixed>>>  $cardFacts
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
        /** The first reason this account may not order yet (CartBlock), null exactly when it may. */
        public ?string $mayNotOrder = null,
        public ?MoneyData $shippingFee = null,
        /** Null: never free. */
        public ?MoneyData $freeAbove = null,
        /** How much more the goods need to ship free; null when free already, or never. */
        public ?MoneyData $freeMissing = null,
        /** The goods' share of the free-above amount, 0 to 100, for the bar. */
        public int $freeShare = 0,
        public ?CartGiftData $nextGift = null,
        public int $pointsToEarn = 0,
        public array $bestSellers = [],
        public array $cardFacts = [],
    ) {}
}
