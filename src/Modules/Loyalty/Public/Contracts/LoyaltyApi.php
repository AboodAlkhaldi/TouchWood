<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Contracts;

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
 * Points, for Sales (loyalty.md §2.1). Ids in, DTOs out. **The writes run inside Sales's own
 * transaction** — refused outside one — and each names the Sales permission that allowed the change;
 * Loyalty checks it in the store the change touches. The reads check nothing of their own: Sales
 * calls them inside flows it has already authorized, and the screens read through Loyalty's queries.
 *
 * Refusals are `DomainError`s with a stable type (`loyalty.redemption_changed`, …).
 */
interface LoyaltyApi
{
    /** About how many points an order of this `goods_total` earns on delivery; 0 while the programme is off. */
    public function earningPreview(string $storeId, Money $goodsTotal): int;

    /** What the customer's points could pay on this order. Reads only; a refusal is answered, not thrown. */
    public function quoteRedemption(RedemptionRequest $request): RedemptionQuote;

    /** Takes the quoted points, inside the order's placement; once per order. */
    public function redeem(RedeemPoints $change): void;

    /** Records the order's delivery and its points, even 0; once per order. */
    public function earn(EarnPoints $change): void;

    /** The customer's live points in the store. Reads only. */
    public function balance(string $customerId, string $storeId): PointsBalanceDto;

    /** An order cancelled before it shipped: every point it used comes back; once. */
    public function orderCancelled(SettleOrder $change): void;

    /** A return completed: its share of the points used and earned settled by running totals; once per return. */
    public function orderReturned(SettleReturn $change): void;

    /** What a staff edit before shipping leaves the points to pay. Reads only. */
    public function pointsAfterEdit(EditedPointsRequest $request): EditedPoints;

    /** A staff edit that lowered the points discount settled; once per edit. Answers what `pointsAfterEdit` did. */
    public function orderEdited(SettleEdit $change): EditedPoints;
}
