<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Points;

use DateTimeImmutable;
use LogicException;
use Modules\Loyalty\Domain\Model\OrderPoints;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Model\Settlement\SettlementKind;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Repository\PointsSettlements;
use Modules\Loyalty\Domain\Service\Settlement;

/**
 * What the three settlements share (loyalty.md §1.8): the order's account locked — its dead lots
 * expired first, as every write does — then the order's row read again under that lock, then the
 * settlement worked out on what the account holds and written. Inside Sales's transaction.
 */
final readonly class OrderSettlement
{
    public function __construct(
        private PointsAccounts $accounts,
        private PointsLedger $ledger,
        private PointsSettlements $settlements,
    ) {}

    /** @return array{PointsAccount, OrderPoints} the account locked, and the order as it is under the lock */
    public function lock(OrderPoints $recorded, DateTimeImmutable $now): array
    {
        $owner = $this->accounts->byId($recorded->accountId) ?? throw new LogicException('An order recorded on no account.');
        $account = $this->accounts->lockOrCreate($owner->customerId, $owner->store, $now);
        $order = $this->ledger->lockOrder($recorded->orderId) ?? throw new LogicException('An order recorded, then gone.');

        return [$account, $order];
    }

    /**
     * @param  callable(Settlement): array{int, int}  $targets  the points to give back and to take back, worked out on the order's facts
     */
    public function settle(PointsAccount $account, string $orderId, SettlementKind $kind, ?string $returnId, callable $targets, int $goodsShareMinor, int $discountShareMinor, DateTimeImmutable $now): Settlement
    {
        $settlement = $this->settlements->snapshot($account, $orderId)->settlement($now);
        [$give, $take] = $targets($settlement);
        $settlement->giveBack($give);
        $settlement->takeBack($take);
        $this->settlements->record($account, $orderId, $kind, $returnId, $settlement, $goodsShareMinor, $discountShareMinor, $now);

        return $settlement;
    }
}
