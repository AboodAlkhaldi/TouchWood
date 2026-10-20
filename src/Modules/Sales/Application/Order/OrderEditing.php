<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\EditedPointsRequest;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\KeptPartDto;
use Modules\Pricing\Public\Enums\PriceKind;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\EditedCouponRequest;
use Modules\Promotions\Public\Dto\GiftOfferDto;
use Modules\Promotions\Public\Enums\Audience as PromotionsAudience;
use Modules\Sales\Application\Quote\QuoteRules;
use Modules\Sales\Domain\Exception\AddressNotUsable;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Domain\Model\OrderForEdit;
use Modules\Sales\Public\Enums\OrderAudience;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * A staff edit worked out (sales.md §1.7 steps 1–6) — reads only; preview and confirm both run it, confirm
 * under the order's lock. The wanted lines are the order's whole: a size and mode not named goes. **A raised
 * quantity adds a part** priced today (a wholesale one by today's band for the line's new total, on the
 * added pieces); **a lowered one takes pieces off the newest part first**. Added and raised lines follow
 * checkout's rules on their new totals — orderable, in their mode, within their limits, wholesale only for
 * a company, their category open — their stock checked by the hold; a lowered line only its limits.
 * **The order's own rules hold**: its VAT rate, its ceiling (`orderDiscountRoom`), its shipping fee and
 * free-above; the coupon can only stay or shrink; the points give way first; and an edit that leaves every
 * line as it is (the address or the gift only) keeps both as they are. The gift: staff keep it, remove it,
 * or take a level the edited order reaches whose gift is in stock beyond what the edit itself holds.
 */
final readonly class OrderEditing
{
    /** Keep the gift as it is. */
    public const string KEEP = 'KEEP';

    /** Remove the gift. */
    public const string REMOVE = 'REMOVE';

    public function __construct(
        private QuoteRules $rules,
        private PricingApi $pricing,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
        private InventoryApi $inventory,
    ) {}

    /**
     * @param  list<CartLineDto>  $wanted  the order's lines as they should be, a size and mode once each
     * @param  string  $gift  `KEEP`, `REMOVE`, or a gift level's id
     *
     * @throws InvalidSalesAttribute|NotOrderable|NotForYourAccount|AddressNotUsable
     */
    public function plan(OrderForEdit $order, array $wanted, ?string $addressId, string $gift): EditPlan
    {
        $store = StoreId::fromString($order->storeId);
        $audience = OrderAudience::from($order->audience);
        $currency = $order->currencyCode;

        // 1. The parts kept and the pieces added, a size and mode at a time.
        $parts = [];

        foreach ($order->lines as $line) {
            if (! $line->isGift) {
                $parts[$line->variantId.'|'.$line->saleMode][] = $line;
            }
        }

        $want = [];

        foreach ($wanted as $line) {
            $key = $line->variantId.'|'.$line->mode->value;
            $want[$key] = isset($want[$key]) ? throw new InvalidSalesAttribute('variant', 'a size and mode once') : $line;
        }

        if ($want === []) {
            throw new InvalidSalesAttribute('quantity', 'at least one line');
        }

        $kept = [];
        $added = [];
        $raised = [];
        $lowered = [];

        foreach (array_unique([...array_keys($parts), ...array_keys($want)]) as $key) {
            $quantity = $want[$key]->quantity ?? 0;
            $lines = $parts[$key] ?? [];
            $now = array_sum(array_map(static fn ($line): int => $line->quantity, $lines));

            if ($quantity > $now) {
                $raised[] = $want[$key];
            } elseif ($quantity < $now) {
                $lowered[] = $want[$key] ?? null;
            }

            // Lowered: off the newest part first; a part left with none goes.
            $off = max(0, $now - $quantity);

            foreach (array_reverse($lines) as $line) {
                $taken = min($off, $line->quantity);
                $off -= $taken;

                if ($line->quantity - $taken > 0) {
                    $kept[] = ['line' => $line, 'quantity' => $line->quantity - $taken];
                }
            }

            if ($quantity > $now) {
                $added[] = new CartLineDto($want[$key]->variantId, $want[$key]->mode, $quantity - $now);
            }
        }

        // The kept parts in the order's own order.
        $position = array_flip(array_map(static fn ($line): string => $line->id, $order->lines));
        usort($kept, static fn (array $a, array $b): int => $position[$a['line']->id] <=> $position[$b['line']->id]);

        if ($raised !== []) {
            $this->rules->lines($store, $raised, $audience, false);
        }

        // A lowered line only within its limits; a removed one (null) not at all.
        $lowered = array_values(array_filter($lowered));

        if ($lowered !== []) {
            $this->rules->limits($store, $lowered);
        }

        $linesKept = $raised === [] && $lowered === [] && count($kept) === count(array_filter($order->lines, static fn ($line): bool => ! $line->isGift));

        // Each size's pieces counted on stock as the hold counts them: every one with no provider when placed;
        // wired, by the size's switch today.
        $sizes = array_values(array_unique(array_map(static fn (CartLineDto $line): string => $line->variantId, $wanted)));
        $stock = $order->wired ? $this->inventory->stock($store, $sizes) : [];
        $counts = [];

        foreach ($sizes as $variantId) {
            $counts[$variantId] = ! $order->wired || ($stock[$variantId]->countsOnStock ?? false);
        }

        // 2. The prices: the kept parts as sold, the added pieces today; the order's own VAT rate.
        $prices = $this->pricing->pricesForEdit($store, array_map(static fn (array $part): KeptPartDto => new KeptPartDto(
            $part['line']->variantId, SaleMode::from($part['line']->saleMode), $part['quantity'],
            Money::of($part['line']->listUnit, $currency), Money::of($part['line']->unit, $currency), PriceKind::from((string) $part['line']->priceKind),
        ), $kept), $added, $order->vatRateBasisPoints);

        if ($prices->unpriced !== []) {
            throw new NotOrderable($prices->unpriced);
        }

        $zero = Money::zero($currency);

        // 3. The room under the ceiling the order was placed under; 4. the coupon, which can only shrink.
        $room = $this->promotions->orderDiscountRoom($order->id, $prices->grossSubtotal, $prices->netSubtotal);
        $couponDiscount = $zero;
        $couponShares = array_map(static fn (): Money => Money::zero($currency), $prices->lines);

        // Every line as it was: the coupon and points stay as they are (§1.7), each line its own share.
        if ($linesKept) {
            $couponDiscount = Money::of($order->couponDiscount, $currency);
            $couponShares = array_map(static fn (array $part): Money => Money::of($part['line']->couponShare, $currency), $kept);
        } elseif ($order->couponId !== null) {
            $coupon = $this->promotions->couponAfterEdit(new EditedCouponRequest(
                $order->id, $order->couponId, $prices, PromotionsAudience::from($order->audience), $room, Money::of($order->couponDiscount, $currency),
            ));

            if ($coupon->isTaken()) {
                $couponDiscount = $coupon->discount ?? $zero;
                $couponShares = $coupon->shares;
            }
        }

        // 5. The points, given way first: within what the coupon left of the room.
        $pointsUsed = 0;
        $pointsDiscount = $zero;

        if ($linesKept) {
            $pointsUsed = $order->pointsUsed;
            $pointsDiscount = Money::of($order->pointsDiscount, $currency);
        } elseif ($order->pointsUsed > 0) {
            $points = $this->loyalty->pointsAfterEdit(new EditedPointsRequest($order->id, $order->storeId, $prices->netSubtotal, $room->subtract($couponDiscount)));
            $pointsUsed = $points->pointsStillCharged;
            $pointsDiscount = $points->discount;
        }

        // 6. The shipping by the order's own rule, then the totals.
        $goods = $this->pricing->totals($prices, $couponDiscount, $pointsDiscount, $zero)->goodsTotal;
        $freeAbove = Money::of($order->shippingRuleFreeAbove, $currency);
        $shipping = ! $freeAbove->isZero() && $goods->compareTo($freeAbove) >= 0 ? $zero : Money::of($order->shippingRuleFee, $currency);
        $totals = $this->pricing->totals($prices, $couponDiscount, $pointsDiscount, $shipping);

        // The gift, staff's choice.
        $options = $this->giftOptions($store, $audience, $goods, $order, $wanted, $counts);
        [$giftLevelId, $giftVariantId] = $this->gift($order, $gift, $options);

        $address = $addressId === null ? null : $this->rules->address($store, $order->customerId, $addressId);

        return new EditPlan(
            $order, $kept, $added, $prices, $couponDiscount, $couponShares, $pointsUsed, $pointsDiscount, $shipping, $totals,
            $giftLevelId, $giftVariantId, $options, $address, $counts, Money::of($order->paid - $order->refunded, $currency)->subtract($totals->orderTotal),
        );
    }

    /**
     * The levels the edited order reaches, highest first, whose gift is in stock: what is free now (the
     * order's own hold already off it) covers the gift and the pieces of its size the edit holds beyond
     * what the order holds now — its bought pieces counted on stock, and its gift if of that size.
     *
     * @param  list<CartLineDto>  $wanted
     * @param  array<string, bool>  $counts
     * @return list<GiftOfferDto>
     */
    private function giftOptions(StoreId $store, OrderAudience $audience, Money $goods, OrderForEdit $order, array $wanted, array $counts): array
    {
        $levels = $this->promotions->giftLevels($store, PromotionsAudience::from($audience->value), $goods);

        if ($levels === []) {
            return [];
        }

        $stock = $this->inventory->stock($store, array_values(array_unique(array_map(static fn ($level): string => $level->variantId, $levels))));

        return array_values(array_filter($levels, static function ($level) use ($stock, $wanted, $counts, $order): bool {
            $size = $level->variantId;
            $wantedNow = ($counts[$size] ?? false) ? array_sum(array_map(static fn (CartLineDto $line): int => $line->variantId === $size ? $line->quantity : 0, $wanted)) : 0;
            $heldNow = array_sum(array_map(static fn ($line): int => $line->variantId === $size && ($line->isGift || $line->countsOnStock) ? $line->quantity : 0, $order->lines));

            return ($stock[$size]->availableAsGift ?? 0) >= 1 + $wantedNow - $heldNow;
        }));
    }

    /**
     * @param  list<GiftOfferDto>  $options
     * @return array{?string, ?string} the level and its size
     *
     * @throws InvalidSalesAttribute
     */
    private function gift(OrderForEdit $order, string $choice, array $options): array
    {
        $choice = in_array(strtoupper(trim($choice)), [self::KEEP, self::REMOVE], true) ? strtoupper(trim($choice)) : $choice;

        if ($choice === self::REMOVE) {
            return [null, null];
        }

        if ($choice === self::KEEP) {
            $line = array_values(array_filter($order->lines, static fn ($line): bool => $line->isGift))[0] ?? null;

            return [$line === null ? null : $order->giftLevelId, $line?->variantId];
        }

        foreach ($options as $option) {
            if ($option->levelId === strtolower(trim($choice))) {
                return [$option->levelId, $option->variantId];
            }
        }

        throw new InvalidSalesAttribute('gift', 'a level the edited order reaches, its gift in stock');
    }
}
