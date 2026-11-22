<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\SettleEditedOrder;

use Carbon\CarbonImmutable;
use Modules\Loyalty\Application\Points\CallerCheck;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\OrderSettlement;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\OrderNotSettleable;
use Modules\Loyalty\Domain\Model\Settlement\SettlementKind;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Repository\PointsSettlements;
use Modules\Loyalty\Domain\Service\Settlement;
use Modules\Loyalty\Domain\Service\SettlementTargets;
use Modules\Loyalty\Public\Dto\EditedPoints;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * Settles a staff edit before shipping that lowered the points discount (loyalty.md §1.8): the
 * points above the new discount come back, to their own lots with their old dates; nothing is taken
 * back — an undelivered order earned nothing. Recorded like a return, keyed by the edit's id, so it
 * counts once. Answers the order's points discount and the points still charged, as
 * `pointsAfterEdit` worked them out. A new discount above the old is refused.
 */
final readonly class SettleEditedOrderHandler
{
    public function __construct(
        private CallerCheck $caller,
        private ProgrammeReader $programmes,
        private PointsLedger $ledger,
        private PointsSettlements $settlements,
        private OrderSettlement $settlement,
    ) {}

    /**
     * @throws InvalidPointsAttribute|Unauthorized|OrderNotSettleable
     */
    public function handle(SettleEditedOrder $command): EditedPoints
    {
        $change = $command->change;
        $store = Inputs::store($change->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $editId = Inputs::id($change->editId, 'edit');
        $recorded = $this->ledger->order($orderId);

        if ($recorded !== null && ! $recorded->store->equals($store)) {
            throw new InvalidPointsAttribute('store', 'not the order\'s recorded store');
        }

        $this->caller->authorize($change->caller, $store);
        $programme = $this->programmes->of($store);
        $newDiscount = Inputs::amount($change->newDiscount, $programme);

        if ($recorded === null || $recorded->pointsRedeemed === 0) {
            throw new OrderNotSettleable($orderId, 'used no points');
        }

        $now = CarbonImmutable::now();
        [$account, $order] = $this->settlement->lock($recorded, $now);

        if ($order->delivered || $order->cancelled) {
            throw new OrderNotSettleable($orderId, $order->delivered ? 'delivered' : 'cancelled');
        }

        if (! $this->settlements->recorded($orderId, $editId)) {
            $facts = $this->settlements->facts($orderId) ?? throw new OrderNotSettleable($orderId, 'used no points');
            $before = $facts->returnedDiscountMinor;
            $lowered = $facts->discountMinor - $before - $newDiscount;

            $this->settlement->settle(
                $account, $orderId, SettlementKind::Edit, $editId,
                static fn (Settlement $settlement): array => [SettlementTargets::edit($settlement->facts, $newDiscount), 0], 0, max(0, $lowered), $now,
            );
        }

        $facts = $this->settlements->facts($orderId) ?? throw new OrderNotSettleable($orderId, 'used no points');

        return new EditedPoints(
            Money::of($facts->discountMinor - $facts->returnedDiscountMinor, $programme->currencyCode),
            $facts->pointsRedeemed - $facts->pointsGivenBack,
        );
    }
}
