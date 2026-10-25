<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

use Shared\Domain\ValueObject\Money;

/**
 * The shopper's cart in a store (sales.md §1.1, §3 ViewCart): its lines as they stand now, whether this account
 * may check out at all (`mayOrder` — a guest signs in first; an account checkout refuses as a whole says so
 * here, not line by line), the coupon code typed (signed in only), what left it and why, and the subtotal of
 * the lines that can be ordered — the lines' totals at today's prices, before any coupon, points, shipping
 * or VAT, which the quote works out (§1.2).
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
    ) {}
}
