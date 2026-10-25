<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

use Modules\Sales\Domain\Model\RemovedLine;
use Shared\Domain\ValueObject\Money;

/**
 * The shopper's cart in a store (sales.md §1.1, §3 ViewCart): its lines as they stand now, the coupon code typed
 * (signed in only), what left it and why (the notice of a sign-in or a change), and the subtotal of the lines
 * that can be ordered — before any coupon, points, shipping or VAT, which the quote works out (§1.2).
 */
final readonly class CartView
{
    /**
     * @param  list<CartLineView>  $lines  in the order added
     * @param  list<RemovedLine>  $notice
     */
    public function __construct(
        public string $storeId,
        public array $lines,
        public ?string $couponCode,
        public array $notice,
        public ?Money $subtotal,
        public int $exponent,
    ) {}
}
