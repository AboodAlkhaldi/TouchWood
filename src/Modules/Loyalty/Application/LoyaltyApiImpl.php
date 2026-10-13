<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application;

use Modules\Loyalty\Application\Command\Earn\Earn;
use Modules\Loyalty\Application\Command\Earn\EarnHandler;
use Modules\Loyalty\Application\Command\Redeem\Redeem;
use Modules\Loyalty\Application\Command\Redeem\RedeemHandler;
use Modules\Loyalty\Application\Command\SettleCancelledOrder\SettleCancelledOrder;
use Modules\Loyalty\Application\Command\SettleCancelledOrder\SettleCancelledOrderHandler;
use Modules\Loyalty\Application\Command\SettleEditedOrder\SettleEditedOrder;
use Modules\Loyalty\Application\Command\SettleEditedOrder\SettleEditedOrderHandler;
use Modules\Loyalty\Application\Command\SettleReturnedOrder\SettleReturnedOrder;
use Modules\Loyalty\Application\Command\SettleReturnedOrder\SettleReturnedOrderHandler;
use Modules\Loyalty\Application\Points\SalesReads;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\EarnPoints;
use Modules\Loyalty\Public\Dto\EditedPoints;
use Modules\Loyalty\Public\Dto\EditedPointsRequest;
use Modules\Loyalty\Public\Dto\PointsBalanceDto;
use Modules\Loyalty\Public\Dto\RedeemPoints;
use Modules\Loyalty\Public\Dto\RedemptionQuote;
use Modules\Loyalty\Public\Dto\RedemptionRequest;
use Modules\Loyalty\Public\Dto\SettleEdit;
use Modules\Loyalty\Public\Dto\SettleOrder;
use Modules\Loyalty\Public\Dto\SettleReturn;
use Shared\Domain\ValueObject\Money;

/**
 * Loyalty's contract for Sales (loyalty.md §2.1): the writes through their handlers, which check the
 * caller's permission in the order's store; the reads through `SalesReads`, which check none.
 */
final readonly class LoyaltyApiImpl implements LoyaltyApi
{
    public function __construct(
        private SalesReads $reads,
        private RedeemHandler $redeem,
        private EarnHandler $earn,
        private SettleCancelledOrderHandler $cancelled,
        private SettleReturnedOrderHandler $returned,
        private SettleEditedOrderHandler $edited,
    ) {}

    public function earningPreview(string $storeId, Money $goodsTotal): int
    {
        return $this->reads->earningPreview($storeId, $goodsTotal);
    }

    public function quoteRedemption(RedemptionRequest $request): RedemptionQuote
    {
        return $this->reads->quoteRedemption($request);
    }

    public function redeem(RedeemPoints $change): void
    {
        $this->redeem->handle(new Redeem($change));
    }

    public function earn(EarnPoints $change): void
    {
        $this->earn->handle(new Earn($change));
    }

    public function balance(string $customerId, string $storeId): PointsBalanceDto
    {
        return $this->reads->balance($customerId, $storeId);
    }

    public function orderCancelled(SettleOrder $change): void
    {
        $this->cancelled->handle(new SettleCancelledOrder($change));
    }

    public function orderReturned(SettleReturn $change): void
    {
        $this->returned->handle(new SettleReturnedOrder($change));
    }

    public function pointsAfterEdit(EditedPointsRequest $request): EditedPoints
    {
        return $this->reads->pointsAfterEdit($request);
    }

    public function orderEdited(SettleEdit $change): EditedPoints
    {
        return $this->edited->handle(new SettleEditedOrder($change));
    }
}
