<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Points;

use Carbon\CarbonImmutable;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\OrderNotSettleable;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Repository\PointsSettlements;
use Modules\Loyalty\Domain\Repository\RedemptionHolds;
use Modules\Loyalty\Domain\Service\PointsArithmetic;
use Modules\Loyalty\Domain\Service\RedemptionRules;
use Modules\Loyalty\Domain\Service\SettlementTargets;
use Modules\Loyalty\Public\Dto\EditedPoints;
use Modules\Loyalty\Public\Dto\EditedPointsRequest;
use Modules\Loyalty\Public\Dto\PointsBalanceDto;
use Modules\Loyalty\Public\Dto\RedemptionQuote;
use Modules\Loyalty\Public\Dto\RedemptionRequest;
use Shared\Domain\ValueObject\Money;

/**
 * The reads Sales makes inside flows it has already authorized (loyalty.md §2.1, §3): **none checks a
 * permission of its own**, and none writes — a dead lot is never counted, and never expired by a read
 * (§1.1). The screens read through Loyalty's queries, which check the reader.
 */
final readonly class SalesReads
{
    public function __construct(
        private ProgrammeReader $programmes,
        private PointsAccounts $accounts,
        private PointsLedger $ledger,
        private PointsSettlements $settlements,
        private RedemptionHolds $holds,
    ) {}

    public function earningPreview(string $storeId, Money $goodsTotal): int
    {
        $programme = $this->programmes->of(Inputs::store($storeId));
        $goods = Inputs::amount($goodsTotal, $programme);

        return $programme->enabled ? PointsArithmetic::earned($goods, $programme->earnPerUnit, $programme->exponent) : 0;
    }

    public function quoteRedemption(RedemptionRequest $request): RedemptionQuote
    {
        $store = Inputs::store($request->storeId);
        $programme = $this->programmes->of($store);
        $netSubtotal = Inputs::amount($request->netSubtotal, $programme);
        $account = $this->accounts->find(Inputs::id($request->customerId, 'customer'), $store);
        // Free points only: those held for a payment in progress are spent by nothing else (§9.6).
        $balance = $account === null ? 0 : max(0, $this->accounts->liveBalance($account, CarbonImmutable::now()) - $this->holds->heldBy($account->id));
        $redemption = RedemptionRules::work(
            $programme, $programme->modeFor($request->audience), $balance, $request->pointsAsked, $netSubtotal,
        );

        return new RedemptionQuote(
            $redemption->mode,
            $redemption->mostUsable,
            $redemption->pointsCharged,
            Money::of($redemption->discountMinor, $programme->currencyCode),
            $redemption->refusal,
        );
    }

    /**
     * What a staff edit before shipping leaves the points to pay (§1.8): the least of the discount
     * now, the cap on the edited net subtotal by the percent the order redeemed under, and the room
     * the coupon left. An order that used no points has none to keep.
     */
    public function pointsAfterEdit(EditedPointsRequest $request): EditedPoints
    {
        $store = Inputs::store($request->storeId);
        $orderId = Inputs::id($request->orderId, 'order');
        $programme = $this->programmes->of($store);
        $net = Inputs::amount($request->editedNetSubtotal, $programme);
        $room = Inputs::amount($request->room, $programme);
        $recorded = $this->ledger->order($orderId);

        if ($recorded !== null && ! $recorded->store->equals($store)) {
            throw new InvalidPointsAttribute('store', 'not the order\'s recorded store');
        }

        $facts = $this->settlements->facts($orderId);

        if ($facts === null || $facts->pointsRedeemed === 0) {
            return new EditedPoints(Money::zero($programme->currencyCode), 0);
        }

        // An edit is the one settlement before shipping: a delivered or cancelled order has none.
        if ($recorded !== null && ($recorded->delivered || $recorded->cancelled)) {
            throw new OrderNotSettleable($orderId, $recorded->delivered ? 'delivered' : 'cancelled');
        }

        [$discount, $points] = SettlementTargets::afterEdit($facts, $facts->capPercent ?? 0, $net, $room);

        return new EditedPoints(Money::of($discount, $programme->currencyCode), $points);
    }

    public function balance(string $customerId, string $storeId): PointsBalanceDto
    {
        $store = Inputs::store($storeId);
        $programme = $this->programmes->of($store);
        $account = $this->accounts->find(Inputs::id($customerId, 'customer'), $store);
        $lots = $account === null ? [] : $this->accounts->liveLots($account, CarbonImmutable::now());
        $points = 0;
        $next = null;
        $nextPoints = 0;

        foreach ($lots as $lot) {
            $points += $lot->pointsLeft;

            // Live lots come in spending order, the one expiring first first.
            if ($next === null || $lot->expiresAt == $next) {
                $next = $lot->expiresAt;
                $nextPoints += $lot->pointsLeft;
            }
        }

        return new PointsBalanceDto(
            $points,
            Money::of(PointsArithmetic::worth($points, $programme->pointsPerUnitOff, $programme->exponent), $programme->currencyCode),
            $next,
            $nextPoints,
            $account === null ? 0 : min($points, $this->holds->heldBy($account->id)),
        );
    }
}
