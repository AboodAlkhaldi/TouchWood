<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\UpdateCouponUse;

use Illuminate\Database\ConnectionInterface;
use Modules\Promotions\Application\Coupon\CallerCheck;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Application\Coupon\Quotes;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\Coupons;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Records a coupon use's new discount after a staff edit (promotions.md §1.3, §2.1), inside Sales's
 * edit transaction: **never higher** — a coupon can only stay or shrink (owner, 2026-10-10) — to 0
 * when the edit removed it, the use still counted. Once per edit: the same edit again with the same
 * discount does nothing.
 */
final readonly class UpdateCouponUseHandler
{
    public function __construct(
        private ConnectionInterface $db,
        private CallerCheck $caller,
        private Coupons $coupons,
    ) {}

    /**
     * @throws InvalidPromotionAttribute|Unauthorized
     */
    public function handle(UpdateCouponUseCommand $command): void
    {
        $change = $command->change;
        $orderId = Inputs::id($change->orderId, 'order');
        $editId = Inputs::id($change->editId, 'edit');
        $use = $this->coupons->useOf($orderId) ?? throw new InvalidPromotionAttribute('order', 'the order used no coupon');
        $store = StoreId::fromString($use->storeId);

        // The scope is the store of the coupon the order used (§2.1).
        $this->caller->authorize($change->caller, $store);

        $this->db->transaction(function () use ($change, $orderId, $editId, $store): void {
            $use = $this->coupons->useOf($orderId, lock: true) ?? throw new InvalidPromotionAttribute('order', 'the order used no coupon');
            $coupon = $this->coupons->byId($store, $use->couponId) ?? throw new InvalidPromotionAttribute('coupon', 'no such coupon');
            Quotes::sameCurrency($coupon, $change->newDiscount->currencyCode, 'the new discount');
            $discount = $change->newDiscount->minorUnits;

            if ($discount < 0) {
                throw new InvalidPromotionAttribute('amount', 'a discount is never below 0');
            }

            if ($use->lastEditId === $editId) {
                if ($use->discountMinor === $discount) {
                    return;
                }

                throw new InvalidPromotionAttribute('edit', 'the edit was recorded with another discount');
            }

            if ($discount > $use->discountMinor) {
                throw new InvalidPromotionAttribute('amount', 'a use\'s discount is never raised');
            }

            $this->coupons->lowerUse($orderId, $editId, $discount);
        });
    }
}
