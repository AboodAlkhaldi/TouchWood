<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ConfirmCoupon;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Promotions\Application\Coupon\CallerCheck;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Public\Dto\SettleReservation;
use Shared\Application\Unauthorized;

/**
 * The paid try's hold becomes the order's use (promotions.md §9.5), recorded with its number exactly as
 * `useCoupon` records one - from then on "a use never comes back". **Never checked again** (owner,
 * 2026-10-29, gap 7.4): the customer paid on the discount they were shown, so a coupon switched off,
 * ended, or limited lower meanwhile (gap 7.1 (b): "100 of 99") is still recorded. Once: a hold already
 * confirmed, released, or gone with its deleted coupon does nothing - the order keeps its own copy of the
 * discount and the code.
 */
final readonly class ConfirmCouponHandler
{
    public function __construct(
        private ConnectionInterface $db,
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
        $orderNumber = Inputs::orderNumber((string) $change->orderNumber);
        $this->caller->authorize($change->caller, $store);

        $seen = $this->coupons->reservationOf($orderId);

        if ($seen === null) {
            return;
        }

        if ($seen->storeId !== $store->value) {
            throw new InvalidPromotionAttribute('store', 'not the order\'s store');
        }

        // The coupon's row first, then the hold's - the order a reserve and a delete lock them in, so a
        // confirm never waits on one waiting on it.
        $this->db->transaction(function () use ($store, $orderId, $orderNumber, $seen): void {
            $coupon = $this->coupons->lock($store, $seen->couponId);
            $held = $this->coupons->reservationOf($orderId, lock: true);

            // Released, confirmed, or gone with its deleted coupon meanwhile: nothing to do.
            if ($coupon === null || $held === null) {
                return;
            }

            $this->coupons->recordUse($coupon, $held->customerId, $orderId, $orderNumber, $held->discountMinor, CarbonImmutable::now());
            $this->coupons->dropReservation($orderId);
        });
    }
}
