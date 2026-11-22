<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

use Modules\Sales\Public\Enums\CartBlock;
use Shared\Domain\ValueObject\Money;

/**
 * The shopper's cart in a store (sales.md §1.1, §3 ViewCart): its lines as they stand now, whether this account
 * may check out at all (`mayOrder` — a guest signs in first; an account checkout refuses as a whole says so
 * here, not line by line), the coupon code typed (signed in only), what left it and why, and the subtotal of
 * the lines that can be ordered — the lines' totals at today's prices, before any coupon, points, shipping
 * or VAT, which the quote works out (§1.2).
 *
 * **What the page tells the shopper beside the lines** (§4.2 B12): why checkout will not take this account
 * (`mayNotOrder`, the first reason; null exactly when `mayOrder`); no shipping fee — it is worked out at
 * checkout (gap 6.6) — and the goods total from which every carrier ships free (`freeAbove`: the store-wide
 * amount, null while none is set); the next gift level not reached yet whose gift is in stock; and about how many points the order earns. These three compare with the goods
 * total as checkout does, as far as the cart knows it (`goodsTotal`): the subtotal less the coupon typed
 * (owner, 2026-10-27) — the points are chosen at checkout, so using them can still move it.
 */
final readonly class CartView
{
    /**
     * @param  list<CartLineView>  $lines  in the order added
     * @param  list<CartNoticeLine>  $notice
     */
    public function __construct(
        public string $storeId,
        public array $lines,
        public bool $mayOrder,
        public ?string $couponCode,
        public array $notice,
        public ?Money $subtotal,
        public int $exponent,
        public ?CartBlock $mayNotOrder = null,
        public ?Money $goodsTotal = null,
        public ?Money $shippingFee = null,
        public ?Money $freeAbove = null,
        public ?CartNextGift $nextGift = null,
        public int $pointsToEarn = 0,
    ) {}
}
