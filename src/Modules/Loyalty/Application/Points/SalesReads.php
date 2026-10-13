<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Points;

use Carbon\CarbonImmutable;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Service\PointsArithmetic;
use Modules\Loyalty\Domain\Service\RedemptionRules;
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
        $balance = $account === null ? 0 : $this->accounts->liveBalance($account, CarbonImmutable::now());
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
        );
    }
}
