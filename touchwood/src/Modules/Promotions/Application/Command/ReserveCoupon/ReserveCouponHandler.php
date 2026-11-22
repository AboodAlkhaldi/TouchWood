<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ReserveCoupon;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Promotions\Application\Coupon\CallerCheck;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Application\Coupon\Quotes;
use Modules\Promotions\Domain\Exception\CouponChanged;
use Modules\Promotions\Domain\Exception\CouponRefused;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\CouponReservation;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Public\Dto\ReserveCoupon;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * Holds the quoted coupon while its customer pays online (promotions.md §9.5), inside Sales's checkout
 * transaction: every check `useCoupon` makes, in the same order, under the same lock on the coupon, and
 * refused or changed exactly as it is. The hold counts toward the coupon's limits while it lasts, so two
 * buyers never both take its last use. The same try again does nothing.
 */
final readonly class ReserveCouponHandler
{
    public function __construct(
        private ConnectionInterface $db,
        private CallerCheck $caller,
        private Coupons $coupons,
        private Quotes $quotes,
        private AccessApi $access,
    ) {}

    /**
     * @throws InvalidPromotionAttribute|Unauthorized|CouponRefused|CouponChanged
     */
    public function handle(ReserveCoupon $change): void
    {
        $request = $change->request;
        $store = Inputs::store($request->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $couponId = Inputs::id($change->couponId, 'coupon');
        $customerId = Inputs::id($request->customerId, 'customer');
        $couponStore = $this->coupons->storeOf($couponId);

        // The scope is the coupon's own store (§2.1): another store passed in is refused.
        if ($couponStore !== null && ! $couponStore->equals($store)) {
            throw new InvalidPromotionAttribute('store', 'not the coupon\'s store');
        }

        $this->caller->authorize($change->caller, $store);
        Quotes::sameStore($store, $request->prices);

        if (! $change->quotedDiscount->isPositive()) {
            throw new InvalidPromotionAttribute('amount', 'a quoted discount is more than 0');
        }

        if ($this->heldAlready($this->coupons->reservationOf($orderId), $couponId, $customerId, $change->quotedDiscount)) {
            return;
        }

        if ($couponStore === null) {
            throw CouponRefused::because(CouponRefusal::UnknownCode);
        }

        if ($this->access->customer($customerId) === null) {
            throw new InvalidPromotionAttribute('customer', 'no such customer');
        }

        try {
            $this->db->transaction(function () use ($change, $request, $store, $orderId, $couponId, $customerId): void {
                $coupon = $this->coupons->lock($store, $couponId) ?? throw CouponRefused::because(CouponRefusal::UnknownCode);

                if ($this->heldAlready($this->coupons->reservationOf($orderId, lock: true), $couponId, $customerId, $change->quotedDiscount)) {
                    return;
                }

                // A try is for an order not made yet: one that already used a coupon is not one.
                if ($this->coupons->useOf($orderId) !== null) {
                    throw new InvalidPromotionAttribute('order', 'the order exists already');
                }

                Quotes::sameCurrency($coupon, $change->quotedDiscount->currencyCode, 'the quoted discount');
                $outcome = $this->quotes->outcome($coupon, $store, $customerId, $request->audience, $request->prices);

                if ($outcome->refusal !== null || $outcome->discount === null) {
                    throw CouponRefused::because($outcome->refusal ?? CouponRefusal::WorthNothing);
                }

                if ($change->usesPoints && ! $coupon->allowWithPoints) {
                    throw CouponRefused::because(CouponRefusal::NotWithPoints);
                }

                if (! $outcome->discount->equals($change->quotedDiscount)) {
                    throw new CouponChanged;
                }

                $this->coupons->reserve($coupon, $customerId, $orderId, $outcome->discount->minorUnits, CarbonImmutable::now());
            });
        } catch (UniqueConstraintViolationException $taken) {
            // Two coupons on one try at once: each locked its own coupon, so only the try's key stops the
            // second. What the first left answers.
            $held = $this->coupons->reservationOf($orderId);

            if ($held === null || $held->couponId === $couponId) {
                throw $taken;
            }

            throw CouponRefused::anotherCoupon();
        }
    }

    /**
     * Whether this try holds this coupon already - then nothing more is done. Another coupon held for
     * the try is refused (one coupon per order, §1.2); another customer's, or another discount, is the
     * caller's mistake.
     */
    private function heldAlready(?CouponReservation $held, string $couponId, string $customerId, Money $quotedDiscount): bool
    {
        if ($held === null) {
            return false;
        }

        if ($held->couponId !== $couponId) {
            throw CouponRefused::anotherCoupon();
        }

        if ($held->customerId !== $customerId) {
            throw new InvalidPromotionAttribute('customer', 'the try is another customer\'s');
        }

        if ($held->discountMinor !== $quotedDiscount->minorUnits) {
            throw new InvalidPromotionAttribute('amount', 'the try holds the coupon for another discount');
        }

        return true;
    }
}
