<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\SettleReturnedOrder;

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
use Shared\Application\Unauthorized;

/**
 * Settles a return completed after delivery (loyalty.md §1.8), by running totals: the points the
 * order used come back for the returned share of its discount, and the points it earned are taken
 * back for the returned share of its goods — its own unspent points first, the rest covered or
 * dropped. Once per return id. Runs while the programme is off. An order whose delivery Loyalty never
 * recorded is refused (§7).
 */
final readonly class SettleReturnedOrderHandler
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
    public function handle(SettleReturnedOrder $command): void
    {
        $change = $command->change;
        $store = Inputs::store($change->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $returnId = Inputs::id($change->returnId, 'return');
        $recorded = $this->ledger->order($orderId);

        if ($recorded !== null && ! $recorded->store->equals($store)) {
            throw new InvalidPointsAttribute('store', 'not the order\'s recorded store');
        }

        $this->caller->authorize($change->caller, $store);
        $programme = $this->programmes->of($store);
        $goods = Inputs::amount($change->goodsShare, $programme);
        $discount = Inputs::amount($change->discountShare, $programme);

        if ($recorded === null || ! $recorded->delivered) {
            throw new OrderNotSettleable($orderId, 'never delivered');
        }

        $now = CarbonImmutable::now();
        [$account] = $this->settlement->lock($recorded, $now);

        if ($this->settlements->recorded($orderId, $returnId)) {
            return;
        }

        $this->settlement->settle(
            $account, $orderId, SettlementKind::Return, $returnId,
            static fn (Settlement $settlement): array => SettlementTargets::return($settlement->facts, $goods, $discount), $goods, $discount, $now,
        );
    }
}
