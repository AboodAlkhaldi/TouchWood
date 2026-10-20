<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Quote;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Enums\AccountType;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Dto\RedemptionRequest;
use Modules\Loyalty\Public\Enums\Audience as LoyaltyAudience;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Enums\Audience as PromotionsAudience;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Exception\AddressNotUsable;
use Modules\Sales\Domain\Exception\MayNotOrder;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Public\Enums\OrderAudience;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A checkout's quote (sales.md §1.2), built in its fixed order (handoff §10.2, §11.3): who may order,
 * what may be bought, the address, the prices, the coupon, the points, the goods total, the gift, the
 * shipping, the totals, the ways to pay, the points it would earn. **A refused coupon or points never
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
        private PlatformApi $platform,
        private AccessApi $access,
        private B2BApi $b2b,
        private CatalogApi $catalog,
        private InventoryApi $inventory,
        private PricingApi $pricing,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
        private Carts $carts,
    ) {}

    /**
     * Prices that end while the quote is built are asked again, once; ending again, `QuoteExpired`.
     *
     * @throws MayNotOrder|NotForYourAccount|NotOrderable|AddressNotUsable|WayToPayNotOffered|QuoteExpired
     */
    public function build(StoreId $store, string $customerId, string $addressId, bool $usePoints, ?int $pointsAsked, WayToPay $wayToPay, int $attempt = 1): QuoteView
    {
        // 1. The store is on, and who may order.
        $audience = $this->audience($store, $customerId);

        // 2. What may be bought — a cart with something in it first.
        $cart = $this->carts->find($store, $customerId, null);

        if ($cart === null || $cart->lines === []) {
            throw new NotOrderable;
        }

        $this->checkLines($store, $cart, $audience);

        // 3. The delivery address.
        $address = Ulids::valid($addressId) ? $this->access->address($addressId) : null;

        if ($address === null || $address->customerId !== $customerId || $address->storeId !== $store->value || ! $address->isComplete) {
            throw new AddressNotUsable;
        }

        // 4. The prices.
        $prices = $this->pricing->prices($store, array_map(static fn ($line): CartLineDto => new CartLineDto($line->variantId, $line->mode, $line->quantity), $cart->lines));

        if ($prices->unpriced !== []) {
            throw new NotOrderable($prices->unpriced);
        }

        $zero = Money::zero($prices->currencyCode);

        // 5. The coupon — the ceiling applied by Promotions; refused, the quote carries why.
        $coupon = $cart->couponCode === null ? null : $this->promotions->applyCoupon(new CouponRequest(
            $store->value, $cart->couponCode, $customerId, PromotionsAudience::from($audience->value), $prices,
        ));
        $couponTaken = $coupon !== null && $coupon->isTaken();
        $couponDiscount = $couponTaken ? ($coupon->discount ?? $zero) : $zero;

        // 6. The points.
        [$pointsUsed, $pointsDiscount, $pointsRefusal, $pointsMode, $pointsMostUsable] = $this->points($store, $customerId, $audience, $prices, $usePoints, $pointsAsked, $couponTaken && ! $coupon->allowWithPoints, $couponDiscount);

        // 7. The goods total.
        $goodsTotal = $this->pricing->totals($prices, $couponDiscount, $pointsDiscount, $zero)->goodsTotal;

        // 8. The gift.
        [$giftLevelId, $giftVariantId] = $this->gift($store, $audience, $goodsTotal, $cart);

        // 9. The shipping — the store's flat fee until stage 7, free from its free-above amount.
        $fee = Money::of($this->platform->setting(SalesSettings::FLAT_FEE, $store)->int(), $prices->currencyCode);
        $freeAbove = Money::of($this->platform->setting(SalesSettings::FREE_ABOVE, $store)->int(), $prices->currencyCode);
        $shipping = ! $freeAbove->isZero() && $goodsTotal->compareTo($freeAbove) >= 0 ? $zero : $fee;

        // 10. The totals.
        $totals = $this->pricing->totals($prices, $couponDiscount, $pointsDiscount, $shipping);

        // 11. The ways to pay.
        $ways = $audience === OrderAudience::Company
            ? ($this->b2b->bankAccount($store->value) === null ? [WayToPay::StaffContact] : [WayToPay::BankTransfer, WayToPay::StaffContact])
            : [WayToPay::StaffContact];

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
            return $attempt === 1 ? $this->build($store, $customerId, $addressId, $usePoints, $pointsAsked, $wayToPay, 2) : throw new QuoteExpired;
        }

        return new QuoteView(
            strtolower((string) Str::ulid()),
            $store->value,
            $customerId,
            strtolower($addressId),
            $audience,
            $prices,
            $cart->couponCode,
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
            $fee,
            $freeAbove,
            $totals,
            $ways,
            $wayToPay,
            $earning,
            $expiresAt,
            $now,
        );
    }

    /** @throws MayNotOrder */
    private function audience(StoreId $store, string $customerId): OrderAudience
    {
        if (! ($this->platform->store($store)->isActive ?? false) || ! $this->access->customerMayOrder($customerId)) {
            throw new MayNotOrder;
        }

        if ($this->access->customer($customerId)?->accountType !== AccountType::Company) {
            return OrderAudience::Public;
        }

        return $this->b2b->isApproved($customerId, $store->value) ? OrderAudience::Company : throw new MayNotOrder;
    }

    /**
     * Each line sold here in its mode, within its limits, open to the account, in stock — the pieces of a
     * size counted over both its modes.
     *
     * @throws NotForYourAccount|NotOrderable
     */
    private function checkLines(StoreId $store, Cart $cart, OrderAudience $audience): void
    {
        $variantIds = array_values(array_unique(array_map(static fn ($line): string => $line->variantId, $cart->lines)));
        $variants = $this->catalog->storeVariants($store, $variantIds);
        $notOrderable = [];
        $pieces = [];

        foreach ($cart->lines as $line) {
            if ($line->mode === SaleMode::Wholesale && $audience !== OrderAudience::Company) {
                throw new NotForYourAccount;
            }

            $variant = $variants[$line->variantId] ?? null;
            [$least, $most] = $line->mode === SaleMode::Wholesale
                ? [$variant?->wholesaleMinimum, $variant?->wholesaleMaximum]
                : [$variant?->retailMinimum, $variant?->retailMaximum];

            if ($variant === null || ! $variant->isActive || ! $variant->orderable || $variant->notAvailableNow
                || ! in_array($line->mode, $variant->saleModes, true) || $line->quantity < ($least ?? 1) || ($most !== null && $line->quantity > $most)) {
                $notOrderable[] = $line->variantId;
            }

            $pieces[$line->variantId] = ($pieces[$line->variantId] ?? 0) + $line->quantity;
        }

        $productIds = array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, $variants)));
        $open = $this->catalog->productsOpenTo($store, $audience === OrderAudience::Company ? ShopperKind::Company : ShopperKind::Individual, $productIds);

        if (array_diff($productIds, $open) !== []) {
            throw new NotForYourAccount;
        }

        $stocks = $this->inventory->stock($store, $variantIds);

        foreach ($variantIds as $variantId) {
            $stock = $stocks[$variantId] ?? null;

            if ($stock === null || ! $stock->orderable || ($stock->available !== null && $stock->available < $pieces[$variantId])) {
                $notOrderable[] = $variantId;
            }
        }

        if ($notOrderable !== []) {
            throw new NotOrderable(array_values(array_unique($notOrderable)));
        }
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

    /**
     * The highest level reached whose size is available as a gift after the order's own pieces of it.
     *
     * @return array{?string, ?string} the level and its size
     */
    private function gift(StoreId $store, OrderAudience $audience, Money $goodsTotal, Cart $cart): array
    {
        $levels = $this->promotions->giftLevels($store, PromotionsAudience::from($audience->value), $goodsTotal);

        if ($levels === []) {
            return [null, null];
        }

        $stock = $this->inventory->stock($store, array_values(array_unique(array_map(static fn ($level): string => $level->variantId, $levels))));

        foreach ($levels as $level) {
            $own = array_sum(array_map(static fn ($line): int => $line->variantId === $level->variantId ? $line->quantity : 0, $cart->lines));

            if (($stock[$level->variantId]->availableAsGift ?? 0) - $own >= 1) {
                return [$level->levelId, $level->variantId];
            }
        }

        return [null, null];
    }
}
