<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ReleaseCoupon;

use Modules\Promotions\Application\Coupon\CallerCheck;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Public\Dto\SettleReservation;
use Shared\Application\Unauthorized;

/**
 * The try did not pay: its hold goes, **nothing counted** - as if Pay had never been pressed
 * (promotions.md §9.5). Once; nothing to release does nothing.
 */
final readonly class ReleaseCouponHandler
{
    public function __construct(
        private CallerCheck $caller,
        private Coupons $coupons,
    ) {}

    /**
     * @throws InvalidPromotionAttribute|Unauthorized
     */
    public function handle(SettleReservation $change): void
    {
        $store = Inputs::store($change->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $this->caller->authorize($change->caller, $store);
        $held = $this->coupons->reservationOf($orderId);

        if ($held !== null && $held->storeId !== $store->value) {
            throw new InvalidPromotionAttribute('store', 'not the order\'s store');
        }

        $this->coupons->dropReservation($orderId);
    }
}
