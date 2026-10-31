<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\ReserveRedemption;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Loyalty\Application\Points\CallerCheck;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\PointsProgrammeOff;
use Modules\Loyalty\Domain\Exception\RedemptionChanged;
use Modules\Loyalty\Domain\Exception\RedemptionRefused;
use Modules\Loyalty\Domain\Model\RedemptionHold;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Repository\RedemptionHolds;
use Modules\Loyalty\Domain\Service\RedemptionRules;
use Modules\Loyalty\Public\Dto\ReservePoints;
use Modules\Loyalty\Public\Enums\RedemptionMode;
use Modules\Loyalty\Public\Enums\RedemptionRefusal;
use Shared\Application\Unauthorized;

/**
 * Holds the quoted points while their customer pays online (loyalty.md §9.6), inside Sales's checkout
 * transaction: every check `redeem` makes, on the free points (the balance less other holds), refused or
 * changed exactly as it is. The hold moves no lot and writes no ledger entry (gap 7.3 (a)); nothing else
 * spends, deducts or covers with it while it lasts. The same try again does nothing.
 */
final readonly class ReserveRedemptionHandler
{
    public function __construct(
        private ConnectionInterface $db,
        private CallerCheck $caller,
        private ProgrammeReader $programmes,
        private AccessApi $access,
        private PointsAccounts $accounts,
        private PointsLedger $ledger,
        private RedemptionHolds $holds,
    ) {}

    /**
     * @throws InvalidPointsAttribute|Unauthorized|PointsProgrammeOff|RedemptionRefused|RedemptionChanged
     */
    public function handle(ReservePoints $change): void
    {
        $store = Inputs::store($change->storeId);
        $orderId = Inputs::id($change->orderId, 'order');
        $customerId = Inputs::id($change->customerId, 'customer');
        $this->caller->authorize($change->caller, $store);

        if ($change->points < 1) {
            throw new InvalidPointsAttribute('points', 'must be a positive whole number');
        }

        if (! $change->discount->isPositive()) {
            throw new RedemptionRefused(RedemptionRefusal::WorthNothing);
        }

        $programme = $this->programmes->of($store);
        $discount = Inputs::amount($change->discount, $programme);
        $netSubtotal = Inputs::amount($change->netSubtotal, $programme);

        if (! $programme->enabled) {
            throw new PointsProgrammeOff;
        }

        if ($this->access->customer($customerId) === null) {
            throw new InvalidPointsAttribute('customer', 'no such customer');
        }

        if ($this->accounts->find($customerId, $store) === null) {
            throw new RedemptionRefused(RedemptionRefusal::NothingToRedeem);
        }

        $this->db->transaction(function () use ($change, $store, $orderId, $customerId, $programme, $discount, $netSubtotal): void {
            $now = CarbonImmutable::now();
            $account = $this->accounts->lockOrCreate($customerId, $store, $now);
            $held = $this->holds->of($orderId, lock: true);

            if ($held !== null) {
                if ($held->accountId !== $account->id || $held->points !== $change->points || $held->discountMinor !== $discount) {
                    throw new InvalidPointsAttribute('points', 'the try holds other points');
                }

                return;
            }

            // A try is for an order not made yet.
            if ($this->ledger->order($orderId) !== null) {
                throw new InvalidPointsAttribute('order', 'the order exists already');
            }

            $mode = $programme->modeFor($change->audience);
            $redemption = RedemptionRules::work(
                $programme, $mode, $this->holds->freePoints($account->id, $now),
                $mode === RedemptionMode::Partial ? $change->points : null, $netSubtotal,
            );

            if ($redemption->refusal !== null || $redemption->pointsCharged !== $change->points || $redemption->discountMinor !== $discount) {
                throw new RedemptionChanged;
            }

            $this->holds->add(new RedemptionHold(
                $orderId, $account->id, $store->value, $redemption->pointsCharged, $redemption->discountMinor,
                $programme->currencyCode, $programme->maxRedemptionPercent, $now,
            ));
        }, 3);
    }
}
