<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\UseCoupon;

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
use Modules\Promotions\Domain\Model\CouponUse;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Public\Dto\UseCoupon;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * Uses the quoted coupon as the order is placed (promotions.md §2.1), inside Sales's checkout
 * transaction, so a one-time coupon can never be used twice (handoff §4.4).
 *
 * Every check of §1.3 is asked again, as the coupon is now, under a lock on the coupon — so two orders
 * never take its last use. No longer usable: `CouponRefused`, with the reason; usable but taking off
 * something else than quoted: `CouponChanged`, and checkout quotes again. It never applies anything the
 * customer did not see. The same order with the same coupon again does nothing; with another coupon it
 * is refused. A use never comes back (owner, 2026-10-08).
 */
final readonly class UseCouponHandler
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
    public function handle(UseCouponCommand $command): void
    {
        $change = $command->change;
        $request = $change->request;
        $store = Inputs::store($request->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $couponId = Inputs::id($change->couponId, 'coupon');
        $customerId = Inputs::id($request->customerId, 'customer');
        $orderNumber = Inputs::orderNumber($change->orderNumber);
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

        if ($this->usedAlready($this->coupons->useOf($orderId), $couponId, $customerId, $change->quotedDiscount)) {
            return;
        }

        // Deleted since the quote — only an unused coupon can be.
        if ($couponStore === null) {
            throw CouponRefused::because(CouponRefusal::UnknownCode);
        }

        if ($this->access->customer($customerId) === null) {
            throw new InvalidPromotionAttribute('customer', 'no such customer');
        }

        try {
            $this->place($change, $store, $orderId, $couponId, $customerId, $orderNumber);
        } catch (UniqueConstraintViolationException $taken) {
            // Two coupons on one order at once: each locked its own coupon, so only the order's unique
            // key stops the second. Its savepoint is gone; what the first left answers.
            $use = $this->coupons->useOf($orderId);

            if ($use === null || $use->couponId === $couponId) {
                throw $taken;
            }

            throw CouponRefused::anotherCoupon();
        }
    }

    /**
     * @throws CouponRefused|CouponChanged|InvalidPromotionAttribute|UniqueConstraintViolationException
     */
    private function place(UseCoupon $change, StoreId $store, string $orderId, string $couponId, string $customerId, string $orderNumber): void
    {
        $request = $change->request;

        $this->db->transaction(function () use ($change, $request, $store, $orderId, $couponId, $customerId, $orderNumber): void {
            $coupon = $this->coupons->lock($store, $couponId) ?? throw CouponRefused::because(CouponRefusal::UnknownCode);

            // Read again under the coupon's lock: the same order placed twice at once finds the first.
            if ($this->usedAlready($this->coupons->useOf($orderId, lock: true), $couponId, $customerId, $change->quotedDiscount)) {
                return;
            }

            Quotes::sameCurrency($coupon, $change->quotedDiscount->currencyCode, 'the quoted discount');
            $outcome = $this->quotes->outcome($coupon, $store, $customerId, $request->audience, $request->prices);

            if ($outcome->refusal !== null || $outcome->discount === null) {
                throw CouponRefused::because($outcome->refusal ?? CouponRefusal::WorthNothing);
            }

            // At checkout the coupon is decided first: one that does not allow points means none (§1.3).
            if ($change->usesPoints && ! $coupon->allowWithPoints) {
                throw CouponRefused::because(CouponRefusal::NotWithPoints);
            }

            if (! $outcome->discount->equals($change->quotedDiscount)) {
                throw new CouponChanged;
            }

            $this->coupons->recordUse($coupon, $customerId, $orderId, $orderNumber, $outcome->discount->minorUnits, CarbonImmutable::now());
        });
    }

    /**
     * Whether this order used this coupon already — then nothing more is done. Another coupon on the
     * order is refused: one coupon per order (§1.2); another customer's order, or the placement replayed
     * with another discount than the one recorded, is the caller's mistake. Once a staff edit lowered
     * the use, the placement's own figure is no longer the recorded one, so a replay is not compared.
     */
    private function usedAlready(?CouponUse $use, string $couponId, string $customerId, Money $quotedDiscount): bool
    {
        if ($use === null) {
            return false;
        }

        if ($use->couponId !== $couponId) {
            throw CouponRefused::anotherCoupon();
        }

        if ($use->customerId !== $customerId) {
            throw new InvalidPromotionAttribute('customer', 'the order is another customer\'s');
        }

        if ($use->lastEditId === null && $use->discountMinor !== $quotedDiscount->minorUnits) {
            throw new InvalidPromotionAttribute('amount', 'the order used the coupon for another discount');
        }

        return true;
    }
}
