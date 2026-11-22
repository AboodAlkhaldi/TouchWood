<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use DateTimeImmutable;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Repository\Carts;

/** What an order bought leaves its cart (§1.3): what was added since stays. */
final readonly class OrderCartEmptying
{
    public function __construct(
        private Carts $carts,
    ) {}

    /** The quoted pieces of each line out of the cart, a line gone when none are left; the quoted coupon. */
    public function of(Cart $cart, QuoteView $view, DateTimeImmutable $now): void
    {
        foreach ($view->prices->lines as $quoted) {
            foreach ($cart->lines as $line) {
                if ($line->variantId !== $quoted->variantId || $line->mode !== $quoted->mode) {
                    continue;
                }

                $left = $line->quantity - $quoted->quantity;
                $left > 0 ? $this->carts->change($cart->id, $line->variantId, $line->mode, $left, $now) : $this->carts->remove($cart->id, $line->variantId, $line->mode, $now);
            }
        }

        if ($cart->couponCode !== null && $cart->couponCode === $view->couponCode) {
            $this->carts->setCoupon($cart->id, null, $now);
        }
    }
}
