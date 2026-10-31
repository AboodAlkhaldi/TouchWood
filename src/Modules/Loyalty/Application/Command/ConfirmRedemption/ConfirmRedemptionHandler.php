<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\ConfirmRedemption;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Loyalty\Application\Points\CallerCheck;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Repository\RedemptionHolds;
use Modules\Loyalty\Public\Dto\SettlePointsHold;
use Shared\Application\Unauthorized;

/**
 * The paid try's held points become the order's redemption (loyalty.md §9.6), recorded exactly as
 * `redeem` records one - from the lots live when they were held - so `orderCancelled` and the returns
 * work on it as built. **Never checked again** (owner, 2026-10-29, gap 7.4): the programme switched off
 * meanwhile still confirms. Once: a hold already confirmed or released does nothing.
 */
final readonly class ConfirmRedemptionHandler
{
    public function __construct(
        private ConnectionInterface $db,
        private CallerCheck $caller,
        private PointsAccounts $accounts,
        private PointsLedger $ledger,
        private RedemptionHolds $holds,
    ) {}

    /**
     * @throws InvalidPointsAttribute|Unauthorized
     */
    public function handle(SettlePointsHold $change): void
    {
        $store = Inputs::store($change->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $orderNumber = Inputs::orderNumber((string) $change->orderNumber);
        $this->caller->authorize($change->caller, $store);
        $held = $this->holds->of($orderId);

        if ($held === null) {
            return;
        }

        if ($held->storeId !== $store->value) {
            throw new InvalidPointsAttribute('store', 'not the order\'s store');
        }

        $account = $this->accounts->byId($held->accountId) ?? throw new LogicException('A hold of an account that is gone.');

        $this->db->transaction(function () use ($account, $store, $orderId, $orderNumber): void {
            $now = CarbonImmutable::now();
            $locked = $this->accounts->lockOrCreate($account->customerId, $store, $now);
            // Read again under the account's lock: a confirm racing a release or itself finds the first.
            $held = $this->holds->of($orderId, lock: true);

            if ($held === null) {
                return;
            }

            if ($held->accountId !== $locked->id) {
                throw new LogicException('A hold re-read under another account\'s lock.');
            }

            // Dropped first: its kept points are then the ones this redemption spends (`HeldLots`).
            $this->holds->drop($orderId);

            if (! $this->ledger->redeem($locked, $orderId, $orderNumber, $held->currencyCode, $held->points, $held->discountMinor, $held->capPercent, $now, $held->reservedAt)) {
                throw new InvalidPointsAttribute('order', 'the order is recorded for another customer');
            }
        }, 3);
    }
}
