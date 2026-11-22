<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\SettleCancelledOrder;

use Carbon\CarbonImmutable;
use Modules\Loyalty\Application\Points\CallerCheck;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\OrderSettlement;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\OrderNotSettleable;
use Modules\Loyalty\Domain\Model\Settlement\SettlementKind;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Service\Settlement;
use Modules\Loyalty\Domain\Service\SettlementTargets;
use Shared\Application\Unauthorized;

/**
 * Settles an order cancelled before it shipped (loyalty.md §1.8): every point it used comes back, to
 * its own lots with their old dates, through those lots' covers first. Once: a cancellation settled
 * again changes nothing. An order Loyalty has no record of used no points and was never delivered —
 * it settles nothing and is not an error (§7). A delivered order is never cancelled.
 */
final readonly class SettleCancelledOrderHandler
{
    public function __construct(
        private CallerCheck $caller,
        private PointsLedger $ledger,
        private OrderSettlement $settlement,
    ) {}

    /**
     * @throws InvalidPointsAttribute|Unauthorized|OrderNotSettleable
     */
    public function handle(SettleCancelledOrder $command): void
    {
        $change = $command->change;
        $store = Inputs::store($change->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $recorded = $this->ledger->order($orderId);

        if ($recorded !== null && ! $recorded->store->equals($store)) {
            throw new InvalidPointsAttribute('store', 'not the order\'s recorded store');
        }

        $this->caller->authorize($change->caller, $store);

        if ($recorded === null) {
            return;
        }

        $now = CarbonImmutable::now();
        [$account, $order] = $this->settlement->lock($recorded, $now);

        if ($order->delivered) {
            throw new OrderNotSettleable($orderId, 'delivered');
        }

        if ($order->cancelled) {
            return;
        }

        $this->settlement->settle(
            $account, $orderId, SettlementKind::Cancellation, null,
            static fn (Settlement $settlement): array => SettlementTargets::cancellation($settlement->facts), 0, 0, $now,
        );
    }
}
