<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\Earn;

use Carbon\CarbonImmutable;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Loyalty\Application\Points\CallerCheck;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\OrderNotSettleable;
use Modules\Loyalty\Domain\Model\OrderPoints;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Service\PointsArithmetic;
use Shared\Application\Unauthorized;

/**
 * Records an order delivered and the points it earns (loyalty.md §1.6): `goods_total` at the store's
 * rate, read at delivery, in a lot that expires `expiry_months` later and is usable at once. **The
 * delivery is recorded even when it earns nothing** — the programme off, or a total that rounds down
 * to 0 — so asking again never earns later. Delivered twice: earned once. A cancelled order is refused.
 */
final readonly class EarnHandler
{
    public function __construct(
        private CallerCheck $caller,
        private ProgrammeReader $programmes,
        private AccessApi $access,
        private PointsAccounts $accounts,
        private PointsLedger $ledger,
    ) {}

    /**
     * @throws InvalidPointsAttribute|Unauthorized|OrderNotSettleable
     */
    public function handle(Earn $command): void
    {
        $change = $command->change;
        $store = Inputs::store($change->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $customerId = Inputs::id($change->customerId, 'customer');
        $orderNumber = Inputs::orderNumber($change->orderNumber);
        $recorded = $this->ledger->order($orderId);

        // The scope is the order's recorded store when Loyalty has one (§2.1).
        if ($recorded !== null && ! $recorded->store->equals($store)) {
            throw new InvalidPointsAttribute('store', 'not the order\'s recorded store');
        }

        $this->caller->authorize($change->caller, $store);

        if ($this->deliveredAlready($recorded, $this->accounts->find($customerId, $store))) {
            return;
        }

        $programme = $this->programmes->of($store);
        $goodsTotal = Inputs::amount($change->goodsTotal, $programme);

        if ($this->access->customer($customerId) === null) {
            throw new InvalidPointsAttribute('customer', 'no such customer');
        }

        $now = CarbonImmutable::now();
        $account = $this->accounts->lockOrCreate($customerId, $store, $now);

        // Read again under the account's lock: a delivery retried, or racing itself, finds the first.
        if ($this->deliveredAlready($this->ledger->lockOrder($orderId), $account)) {
            return;
        }

        // Whether the programme is on is read at delivery, not when the order was placed (§1.6).
        $points = $programme->enabled
            ? PointsArithmetic::earned($goodsTotal, $programme->earnPerUnit, $programme->exponent)
            : 0;

        $recordedNow = $this->ledger->earn(
            $account, $orderId, $orderNumber, $programme->currencyCode, $goodsTotal, $points,
            $now->addMonthsNoOverflow($programme->expiryMonths), $now,
        );

        if (! $recordedNow) {
            throw new InvalidPointsAttribute('customer', 'the order is another customer\'s');
        }
    }

    /**
     * Whether the order's delivery is recorded already — then nothing more is done. An order Loyalty
     * holds for another account, or cancelled, is refused.
     */
    private function deliveredAlready(?OrderPoints $order, ?PointsAccount $account): bool
    {
        if ($order === null) {
            return false;
        }

        if ($account === null || $order->accountId !== $account->id) {
            throw new InvalidPointsAttribute('customer', 'the order is another customer\'s');
        }

        if ($order->cancelled) {
            throw new OrderNotSettleable($order->orderId, 'cancelled');
        }

        return $order->delivered;
    }
}
