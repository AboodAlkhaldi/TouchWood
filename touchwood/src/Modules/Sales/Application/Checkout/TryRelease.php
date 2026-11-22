<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\CallerPermission as LoyaltyCaller;
use Modules\Loyalty\Public\Dto\SettlePointsHold;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CallerPermission as PromotionsCaller;
use Modules\Promotions\Public\Dto\SettleReservation;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Model\CheckoutTry;
use Modules\Sales\Domain\Repository\CheckoutTries;

/**
 * A try that did not pay (§9.5 #4): every reservation given back with nothing counted, Inventory last; the cart
 * untouched. **Only while it is live**: once its order is made, the order shares the try's id for its stock.
 */
final readonly class TryRelease
{
    public function __construct(
        private ConnectionInterface $db,
        private CheckoutTries $tries,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
        private InventoryApi $inventory,
    ) {}

    public function release(string $tryId): void
    {
        $this->db->transaction(function () use ($tryId): void {
            $try = $this->tries->lock($tryId);

            if ($try !== null && $try->isLive()) {
                $this->giveBack($try);
            }
        }, 3);
    }

    /**
     * A currency refused (§9.5 #4): the money is kept by Payments and never enters an order, so this is the
     * amount asked. What really came is in Payments' list.
     */
    public function notAccepted(string $tryId): void
    {
        $this->db->transaction(function () use ($tryId): void {
            $try = $this->tries->lock($tryId);

            if ($try === null) {
                return;
            }

            if ($try->isLive()) {
                $this->giveBack($try);
            }

            if ($try->refundDue === null) {
                $this->tries->addRefundDue($try->id, $try->amount);
            }
        }, 3);
    }

    private function giveBack(CheckoutTry $try): void
    {
        // Both, whatever the quote held: each is nothing when nothing is reserved.
        $this->promotions->releaseCoupon(new SettleReservation($try->storeId, $try->orderId, null, new PromotionsCaller('sales', SalesPermissions::ORDER_PLACE)));
        $this->loyalty->releaseRedemption(new SettlePointsHold($try->storeId, $try->orderId, null, new LoyaltyCaller('sales', SalesPermissions::ORDER_PLACE)));
        $this->inventory->release($try->orderId);
        $this->tries->end($try->id, CheckoutTry::RELEASED, CarbonImmutable::now());
    }
}
