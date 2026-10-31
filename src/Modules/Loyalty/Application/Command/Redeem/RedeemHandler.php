<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\Redeem;

use Carbon\CarbonImmutable;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Loyalty\Application\Points\CallerCheck;
use Modules\Loyalty\Application\Points\Inputs;
use Modules\Loyalty\Application\Points\ProgrammeReader;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\OrderNotSettleable;
use Modules\Loyalty\Domain\Exception\PointsProgrammeOff;
use Modules\Loyalty\Domain\Exception\RedemptionChanged;
use Modules\Loyalty\Domain\Exception\RedemptionRefused;
use Modules\Loyalty\Domain\Model\OrderPoints;
use Modules\Loyalty\Domain\Model\PointsAccount;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Repository\RedemptionHolds;
use Modules\Loyalty\Domain\Service\RedemptionRules;
use Modules\Loyalty\Public\Dto\RedeemPoints;
use Modules\Loyalty\Public\Enums\RedemptionMode;
use Modules\Loyalty\Public\Enums\RedemptionRefusal;
use Shared\Application\Unauthorized;

/**
 * Takes the quoted points as the order is placed (loyalty.md §1.7), inside Sales's transaction, so an
 * order and its points stand or fall together.
 *
 * The redemption is worked out again with the balance and settings of this moment; if it no longer
 * works out as quoted — refused by the rules now, or different — it is `RedemptionChanged`, and
 * checkout quotes again (owner, 2026-10-11). It never applies anything the customer did not see.
 * `RedemptionRefused` answers only a placement no quote could give: no points at all, or a discount
 * of 0. Placed twice for one order: once — answered before anything that may have changed since.
 */
final readonly class RedeemHandler
{
    public function __construct(
        private CallerCheck $caller,
        private ProgrammeReader $programmes,
        private AccessApi $access,
        private PointsAccounts $accounts,
        private PointsLedger $ledger,
        private RedemptionHolds $holds,
    ) {}

    /**
     * @throws InvalidPointsAttribute|Unauthorized|PointsProgrammeOff|RedemptionRefused|RedemptionChanged|OrderNotSettleable
     */
    public function handle(Redeem $command): void
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

        if ($change->points < 1) {
            throw new InvalidPointsAttribute('points', 'must be a positive whole number');
        }

        // A discount of 0 is refused, never charged (§1.7 step 4): no quote gives one.
        if (! $change->discount->isPositive()) {
            throw new RedemptionRefused(RedemptionRefusal::WorthNothing);
        }

        $account = $this->accounts->find($customerId, $store);

        if ($this->placedAlready($recorded, $account, $change)) {
            return;
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

        // No account, no points — no quote offered any: refused without making one (§1.1, an account
        // is made by what it records).
        if ($account === null) {
            throw new RedemptionRefused(RedemptionRefusal::NothingToRedeem);
        }

        $now = CarbonImmutable::now();
        $account = $this->accounts->lockOrCreate($customerId, $store, $now);

        // Read again under the account's lock: a placement retried, or racing itself, finds the first.
        if ($this->placedAlready($this->ledger->lockOrder($orderId), $account, $change)) {
            return;
        }

        // An order paid online is made from its hold by `confirmRedemption`, never placed again (§9.6).
        if ($this->holds->of($orderId) !== null) {
            throw new InvalidPointsAttribute('order', 'the order holds points for an online payment');
        }

        $mode = $programme->modeFor($change->audience);
        $redemption = RedemptionRules::work(
            // Only free points: those held for a payment in progress are spent by nothing else (§9.6).
            $programme, $mode, $this->holds->freePoints($account->id, $now), $mode === RedemptionMode::Partial ? $change->points : null, $netSubtotal,
        );

        // Refused now, or different: either way no longer what the customer saw (§7).
        if ($redemption->refusal !== null || $redemption->pointsCharged !== $change->points || $redemption->discountMinor !== $discount) {
            throw new RedemptionChanged;
        }

        $taken = $this->ledger->redeem(
            $account, $orderId, $orderNumber, $programme->currencyCode,
            $redemption->pointsCharged, $redemption->discountMinor, $programme->maxRedemptionPercent, $now,
        );

        if (! $taken) {
            throw new InvalidPointsAttribute('customer', 'the order is another customer\'s');
        }
    }

    /**
     * Whether this order has redeemed these points already — then nothing more is done. An order
     * Loyalty holds for another account, or with other points, or delivered or cancelled without a
     * redemption, is refused.
     */
    private function placedAlready(?OrderPoints $order, ?PointsAccount $account, RedeemPoints $change): bool
    {
        if ($order === null) {
            return false;
        }

        if ($account === null || $order->accountId !== $account->id) {
            throw new InvalidPointsAttribute('customer', 'the order is another customer\'s');
        }

        if ($order->pointsRedeemed === 0) {
            throw new OrderNotSettleable($order->orderId, 'no longer being placed');
        }

        if ($order->pointsRedeemed !== $change->points || $order->discountMinor !== $change->discount->minorUnits) {
            throw new InvalidPointsAttribute('points', 'the order redeemed other points');
        }

        return true;
    }
}
