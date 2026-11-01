<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Quote;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\RedemptionRequest;
use Modules\Loyalty\Public\Enums\Audience as LoyaltyAudience;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Enums\Audience as PromotionsAudience;
use Modules\Sales\Domain\Exception\AddressInOtherStore;
use Modules\Sales\Domain\Exception\AddressNotUsable;
use Modules\Sales\Domain\Exception\MayNotOrder;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\TooManyWaitingOrders;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Public\Enums\OrderAudience;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * A checkout's quote (sales.md §1.2), built in its fixed order (handoff §10.2, §11.3): who may order,
 * what may be bought, the address, the prices, the coupon, the points, the goods total, the gift, the
 * shipping (Shipping's quote for the address), the totals, the ways to pay, the points it would earn. **A refused coupon or points never
 * fail the quote** (answer 31): it is built without them, with the reason. Reads only.
 */
final readonly class QuoteBuilder
{
    /** How long a quote holds, at most (handoff §12.3). */
    public const int MINUTES = 15;

    /** Points skipped: the coupon taken does not allow points (§1.2 step 6). */
    public const string NOT_WITH_COUPON = 'NOT_WITH_COUPON';

    /** Points refused whole: more than the coupon left of the discount room (handoff §11.3). */
    public const string PASSES_CEILING = 'PASSES_CEILING';

    public function __construct(
        private QuoteRules $rules,
        private PricingApi $pricing,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
        private Carts $carts,
        private ShippingQuotes $shipping,
    ) {}

    /**
     * Prices that end while the quote is built are asked again, once; ending again, `QuoteExpired`. The
     * carrier asked for is picked when it serves the address, else the cheapest (gap 6.9).
     *
     * @throws MayNotOrder|TooManyWaitingOrders|NotForYourAccount|NotOrderable|AddressNotUsable|AddressInOtherStore|WayToPayNotOffered|QuoteExpired
     */
    public function build(StoreId $store, string $customerId, string $addressId, bool $usePoints, ?int $pointsAsked, WayToPay $wayToPay, ?string $carrierId = null, int $attempt = 1): QuoteView
    {
        // 1. The store is on, who may order, and fewer orders waiting than allowed.
        $audience = $this->rules->audience($store, $customerId);
        $this->rules->waiting($store, $customerId);

        // 2. What may be bought — a cart with something in it first.
        $cart = $this->carts->find($store, $customerId, null);
        $lines = array_map(static fn ($line): CartLineDto => new CartLineDto($line->variantId, $line->mode, $line->quantity), $cart === null ? [] : $cart->lines);
        $this->rules->lines($store, $lines, $audience);

        // 3. The delivery address.
        $address = $this->rules->address($store, $customerId, $addressId);

        // 4. The prices.
        $prices = $this->pricing->prices($store, $lines);

        if ($prices->unpriced !== []) {
            throw new NotOrderable($prices->unpriced);
        }

        $zero = Money::zero($prices->currencyCode);

        // 5. The coupon — the ceiling applied by Promotions; refused, the quote carries why.
        $couponCode = $cart?->couponCode;
        $coupon = $couponCode === null ? null : $this->promotions->applyCoupon(new CouponRequest(
            $store->value, $couponCode, $customerId, PromotionsAudience::from($audience->value), $prices,
        ));
        $couponTaken = $coupon !== null && $coupon->isTaken();
        $couponDiscount = $couponTaken ? ($coupon->discount ?? $zero) : $zero;

        // 6. The points.
        [$pointsUsed, $pointsDiscount, $pointsRefusal, $pointsMode, $pointsMostUsable] = $this->points($store, $customerId, $audience, $prices, $usePoints, $pointsAsked, $couponTaken && ! $coupon->allowWithPoints, $couponDiscount);

        // 7. The goods total.
        $goodsTotal = $this->pricing->totals($prices, $couponDiscount, $pointsDiscount, $zero)->goodsTotal;

        // 8. The gift.
        [$giftLevelId, $giftVariantId] = $this->rules->gift($store, $audience, $goodsTotal, $lines);

        // 9. The shipping — Shipping's carriers for the address, for this goods total (§9.5 #2). Another
        // store's country is refused; no carrier serving the place leaves the fee to staff.
        $shipping = $this->shipping->quote($store, ShippingQuotes::destination($address), $goodsTotal, $carrierId);

        // 10. The totals — no VAT on the shipping (§9.5 #1).
        $totals = $this->pricing->totals($prices, $couponDiscount, $pointsDiscount, $shipping->fee);

        // 11. The ways to pay — only staff's contact while the fee is to be set, a company's too (shipping.md §9.3 #7).
        $ways = $shipping->pending() ? [WayToPay::StaffContact] : $this->rules->waysToPay($store, $audience);

        if (! in_array($wayToPay, $ways, true)) {
            throw new WayToPayNotOffered;
        }

        // 12. The points it would earn.
        $earning = $this->loyalty->earningPreview($store->value, $goodsTotal);

        // Whole seconds, as the table keeps them — the end rounded down, so a quote never outlives its prices.
        $now = CarbonImmutable::now()->startOfSecond();
        $expiresAt = $now->addMinutes(self::MINUTES);

        if ($prices->validUntil !== null && $prices->validUntil < $expiresAt) {
            $expiresAt = CarbonImmutable::instance($prices->validUntil)->startOfSecond();
        }

        if ($expiresAt <= $now) {
            return $attempt === 1 ? $this->build($store, $customerId, $addressId, $usePoints, $pointsAsked, $wayToPay, $carrierId, 2) : throw new QuoteExpired;
        }

        return new QuoteView(
            strtolower((string) Str::ulid()),
            $store->value,
            $customerId,
            strtolower($addressId),
            $audience,
            $prices,
            $couponCode,
            $couponTaken ? $coupon->couponId : null,
            $coupon?->nameAr,
            $coupon?->nameEn,
            $couponDiscount,
            $couponTaken ? array_map(static fn (Money $share): int => $share->minorUnits, $coupon->shares) : [],
            $couponTaken && $coupon->allowWithPoints,
            $coupon?->refusal?->value,
            $usePoints,
            $pointsAsked,
            $pointsMode,
            $pointsMostUsable,
            $pointsUsed,
            $pointsDiscount,
            $pointsRefusal,
            $giftLevelId,
            $giftVariantId,
            $shipping,
            $totals,
            $ways,
            $wayToPay,
            $earning,
            $expiresAt,
            $now,
        );
    }

    /**
     * @return array{int, Money, ?string, ?string, int} the points used, what they take off, why none were, and how the buyer redeems and how many they could
     */
    private function points(StoreId $store, string $customerId, OrderAudience $audience, CartPricesDto $prices, bool $usePoints, ?int $pointsAsked, bool $couponForbids, Money $couponDiscount): array
    {
        $zero = Money::zero($prices->currencyCode);

        if (! $usePoints) {
            return [0, $zero, null, null, 0];
        }

        if ($couponForbids) {
            return [0, $zero, self::NOT_WITH_COUPON, null, 0];
        }

        $quote = $this->loyalty->quoteRedemption(new RedemptionRequest(
            $customerId, $store->value, LoyaltyAudience::from($audience->value), $pointsAsked, $prices->netSubtotal,
        ));

        if ($quote->refusal !== null) {
            return [0, $zero, $quote->refusal->value, $quote->mode->value, $quote->mostUsable];
        }

        $room = $this->promotions->customerDiscountRoom($store, $prices->grossSubtotal, $prices->netSubtotal);

        if ($quote->discount->compareTo($room->subtract($couponDiscount)) > 0) {
            return [0, $zero, self::PASSES_CEILING, $quote->mode->value, $quote->mostUsable];
        }

        return [$quote->pointsCharged, $quote->discount, null, $quote->mode->value, $quote->mostUsable];
    }
}
