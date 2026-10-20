<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Access\Public\Dto\AddressDto;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\TotalsDto;
use Modules\Promotions\Public\Dto\GiftOfferDto;
use Modules\Sales\Domain\Model\OrderForEdit;
use Modules\Sales\Domain\Model\OrderLine;
use Shared\Domain\ValueObject\Money;

/**
 * A staff edit worked out (sales.md §1.7 steps 1–6) — what staff see before confirming, and what
 * confirming writes: the parts kept (each with its new quantity) and the pieces added, priced as the
 * order's own (`prices`: the kept parts, then the added lines, by place); the coupon and points as the
 * edit leaves them; the shipping by the order's own rule; the totals; the gift chosen and the levels it
 * could be; the address when it changes; and the money difference — what the order holds less its new
 * total (above 0: a refund due; below: a balance due).
 */
final readonly class EditPlan
{
    /**
     * @param  list<array{line: OrderLine, quantity: int}>  $kept
     * @param  list<CartLineDto>  $added
     * @param  list<Money>  $couponShares  one a priced line, in their order
     * @param  list<GiftOfferDto>  $giftOptions  the levels the edited order reaches whose gift is in stock
     */
    public function __construct(
        public OrderForEdit $order,
        public array $kept,
        public array $added,
        public CartPricesDto $prices,
        public Money $couponDiscount,
        public array $couponShares,
        public int $pointsUsed,
        public Money $pointsDiscount,
        public Money $shipping,
        public TotalsDto $totals,
        public ?string $giftLevelId,
        public ?string $giftVariantId,
        public array $giftOptions,
        public ?AddressDto $address,
        public Money $difference,
    ) {}
}
