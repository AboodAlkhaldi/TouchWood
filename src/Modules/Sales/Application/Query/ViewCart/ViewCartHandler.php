<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

use Modules\Access\Public\Dto\CustomerDto;
use Modules\Access\Public\Enums\AccountType;
use Modules\Access\Public\Enums\CustomerStatus;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Enums\Audience as PromotionsAudience;
use Modules\Sales\Application\Cart\CartBuyer;
use Modules\Sales\Application\Order\SizeRecords;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Model\Cart;
use Modules\Sales\Domain\Model\RemovedLine;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Public\Enums\CartBlock;
use Modules\Sales\Public\Enums\CartLineProblem;
use Modules\Sales\Public\Enums\CartNoticeReason;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The cart** (sales.md §1.1, §3 ViewCart) — a customer's or a guest's in a store, read only. Each line as it
 * stands now: its card, today's prices, and whether it can be ordered — the checkout's own rules (§1.2 step 2)
 * marked on each line instead of refusing, so the shopper sees what to change before checking out. A guest
 * sees what either kind of account sees and may hold wholesale lines (§1.1): only what is true for any
 * account is marked on them, the account's own rules waiting for the sign-in. A cart nobody made yet is an
 * empty one.
 */
final readonly class ViewCartHandler
{
    public const string PERMISSION = SalesPermissions::CART_MANAGE;

    public function __construct(
        private CartBuyer $buyer,
        private Authorizer $authorizer,
        private Carts $carts,
        private CatalogApi $catalog,
        private PricingApi $pricing,
        private InventoryApi $inventory,
        private SizeRecords $sizes,
        private PlatformApi $platform,
        private B2BApi $b2b,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute
     */
    public function handle(ViewCart $query): CartView
    {
        [$store, $customerId, $guestId, $accountType, $customer] = $this->buyer->of($query->storeId);
        $this->authorizer->authorize($customerId === null ? SalesPermissions::GUEST_CART_MANAGE : self::PERMISSION, PermissionScope::store($store));
        $locale = in_array($query->locale, ['ar', 'en'], true) ? $query->locale : throw new InvalidSalesAttribute('locale', 'ar or en');
        $company = $customerId !== null && $accountType === AccountType::Company && $this->b2b->isApproved($customerId, $store->value);
        // Whether checkout would take this account at all (§1.2 step 1), and the first reason it would not.
        $block = self::block($customerId, $customer, $company);
        $mayOrder = $block === null;
        $cart = $customerId === null && $guestId === null ? null : $this->carts->find($store, $customerId, $guestId);
        $records = $cart === null ? [] : $this->sizes->of(array_values(array_unique([
            ...array_map(static fn ($line): string => $line->variantId, $cart->lines),
            ...array_map(static fn (RemovedLine $line): string => $line->variantId, $cart->notice),
        ])));
        $notice = $cart === null ? [] : self::notice($cart, $records, $locale);

        if ($cart === null || $cart->lines === []) {
            $currency = $this->platform->store($store)?->currencyCode;
            [$fee, $freeAbove] = $currency === null ? [null, null] : $this->shipping($store, $currency);

            return new CartView(
                $store->value, [], $mayOrder, $cart?->couponCode, $notice, null, $currency === null ? 2 : ($this->platform->currency($currency)->exponent ?? 2),
                $block, null, $fee, $freeAbove,
            );
        }

        $viewer = $customerId === null ? ShopperKind::Guest : ($accountType === AccountType::Company ? ShopperKind::Company : ShopperKind::Individual);
        $variantIds = array_values(array_unique(array_map(static fn ($line): string => $line->variantId, $cart->lines)));
        $variants = $this->catalog->storeVariants($store, $variantIds);
        $productIds = array_values(array_unique(array_map(static fn (array $record): string => $record['productId'], array_intersect_key($records, array_flip($variantIds)))));
        $open = array_flip($this->catalog->productsOpenTo($store, $viewer, $productIds));
        $cards = $this->catalog->productCards($store, $locale, $productIds);
        $photoIds = array_values(array_filter(array_map(static fn ($card): ?string => $card->photoMediaId, $cards)));
        $photos = $photoIds === [] ? [] : $this->platform->mediaUrlsOf($photoIds);
        $stock = $this->inventory->stock($store, $variantIds);
        $prices = $this->pricing->prices($store, array_map(static fn ($line): CartLineDto => new CartLineDto($line->variantId, $line->mode, $line->quantity), $cart->lines));
        $priced = [];

        foreach ($prices->lines as $price) {
            $priced[$price->variantId.'|'.$price->mode->value] = $price;
        }

        $pieces = [];

        foreach ($cart->lines as $line) {
            $pieces[$line->variantId] = ($pieces[$line->variantId] ?? 0) + $line->quantity;
        }

        $lines = [];
        $subtotal = null;
        $orderable = [];

        foreach ($cart->lines as $line) {
            $record = $records[$line->variantId] ?? null;

            // A size gone meanwhile (its line goes with it): nothing to show.
            if ($record === null) {
                continue;
            }

            $variant = $variants[$line->variantId] ?? null;
            $price = $priced[$line->variantId.'|'.$line->mode->value] ?? null;
            $wholesale = $line->mode === SaleMode::Wholesale;
            [$least, $most] = $wholesale ? [$variant->wholesaleMinimum ?? 1, $variant?->wholesaleMaximum] : [$variant->retailMinimum ?? 1, $variant?->retailMaximum];
            $held = $stock[$line->variantId] ?? null;

            $problem = match (true) {
                $variant === null || ! $variant->isActive || ! $variant->orderable || $variant->notAvailableNow
                    || ! in_array($line->mode, $variant->saleModes, true) => CartLineProblem::NotAvailable,
                // Wholesale for a signed-in account that is not an approved company; a guest's waits for the sign-in.
                ($wholesale && $customerId !== null && ! $company) || ! isset($open[$record['productId']]) => CartLineProblem::NotForYourAccount,
                $line->quantity < $least => CartLineProblem::BelowMinimum,
                $most !== null && $line->quantity > $most => CartLineProblem::AboveMaximum,
                $held === null || ! $held->orderable || ($held->available !== null && $held->available < $pieces[$line->variantId]) => CartLineProblem::OutOfStock,
                $price === null => CartLineProblem::NotPriced,
                default => null,
            };

            // A line that can be ordered has its price (an unpriced one is NOT_PRICED).
            if ($problem === null) {
                $subtotal = ($subtotal ?? Money::zero($prices->currencyCode))->add($price->total);
                $orderable[] = $price;
            }

            $card = $cards[$record['productId']] ?? null;
            $lines[] = new CartLineView(
                $line->variantId, $record['productId'], $card === null ? self::name($record, $locale) : $card->name,
                $card?->slug, $card?->photoMediaId === null ? null : ($photos[strtolower($card->photoMediaId)]->variants ?? null),
                $record['code'], $record['values'], $line->mode->value, $line->quantity,
                $price?->unit, $price?->listUnit, $price?->total, $problem, $least, $most,
            );
        }

        $audience = $company ? PromotionsAudience::Company : PromotionsAudience::Public;
        $goods = $this->goodsTotal($prices, $orderable, $subtotal, $customerId, $cart->couponCode, $audience);
        [$fee, $freeAbove] = $this->shipping($store, $prices->currencyCode);

        return new CartView(
            $store->value, $lines, $mayOrder, $cart->couponCode, $notice, $subtotal, $this->platform->currency($prices->currencyCode)->exponent ?? 2,
            $block, $goods, $fee, $freeAbove,
            $goods === null ? null : $this->nextGift($store, $audience, $goods, $pieces),
            $goods === null || $customerId === null ? 0 : $this->loyalty->earningPreview($store->value, $goods),
        );
    }

    /**
     * The first reason checkout would not take this account (§1.2 step 1) — Access's own rule for the person
     * (`Customer::mayOrder`: active, both verified, no deletion asked for, not emptied; and `customerMayOrder`'s
     * phone SMS reaches, named apart because only another number helps), then B2B's for a
     * company — or null when it would.
     */
    private static function block(?string $customerId, ?CustomerDto $customer, bool $approvedCompany): ?CartBlock
    {
        return match (true) {
            $customerId === null => CartBlock::SignIn,
            $customer === null || $customer->status !== CustomerStatus::Active || $customer->deletionScheduledFor !== null || $customer->anonymized => CartBlock::AccountNotActive,
            ! $customer->emailVerified => CartBlock::EmailUnverified,
            // Before "verify": such a phone reads as unverified, but only another number helps.
            ! $customer->phoneCountryAllowed => CartBlock::PhoneCountryNotServed,
            ! $customer->phoneVerified => CartBlock::PhoneUnverified,
            $customer->accountType === AccountType::Company && ! $approvedCompany => CartBlock::CompanyNotApproved,
            default => null,
        };
    }

    /**
     * The goods total as far as the cart knows it: the orderable lines' subtotal less the coupon typed, as
     * Promotions would take it off them at checkout (§1.2 step 5) — nothing when it refuses. Null with
     * nothing orderable.
     *
     * @param  list<LinePriceDto>  $orderable
     */
    private function goodsTotal(CartPricesDto $prices, array $orderable, ?Money $subtotal, ?string $customerId, ?string $couponCode, PromotionsAudience $audience): ?Money
    {
        if ($subtotal === null || $customerId === null || $couponCode === null) {
            return $subtotal;
        }

        $gross = Money::zero($prices->currencyCode);

        foreach ($orderable as $line) {
            $gross = $gross->add($line->listTotal);
        }

        $coupon = $this->promotions->applyCoupon(new CouponRequest($prices->storeId, $couponCode, $customerId, $audience, new CartPricesDto(
            $prices->storeId, $prices->currencyCode, $prices->taxRateBasisPoints, $orderable, [], $gross, $subtotal, $prices->pricedAt, $prices->validUntil,
        )));

        return $coupon->isTaken() && $coupon->discount !== null ? $subtotal->subtract($coupon->discount) : $subtotal;
    }

    /**
     * The store's flat fee and the goods total from which shipping is free — null when it never is (§1.2 step 9).
     *
     * @return array{Money, ?Money}
     */
    private function shipping(StoreId $store, string $currency): array
    {
        $freeAbove = $this->platform->setting(SalesSettings::FREE_ABOVE, $store)->int();

        return [
            Money::of($this->platform->setting(SalesSettings::FLAT_FEE, $store)->int(), $currency),
            $freeAbove === 0 ? null : Money::of($freeAbove, $currency),
        ];
    }

    /**
     * The lowest gift level the goods do not reach yet whose gift is in stock after the cart's own pieces of
     * it, as checkout would hold it (§1.2 step 8).
     *
     * @param  array<string, int>  $pieces  variant => the cart's pieces of it
     */
    private function nextGift(StoreId $store, PromotionsAudience $audience, Money $goods, array $pieces): ?CartNextGift
    {
        $levels = $this->promotions->giftLevelsAbove($store, $audience, $goods);

        if ($levels === []) {
            return null;
        }

        $stock = $this->inventory->stock($store, array_values(array_unique(array_map(static fn ($level): string => $level->variantId, $levels))));

        foreach ($levels as $level) {
            if (($stock[$level->variantId]->availableAsGift ?? 0) - ($pieces[$level->variantId] ?? 0) >= 1) {
                return new CartNextGift($level->amount, $level->amount->subtract($goods));
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{productId: string, nameAr: string, nameEn: string, values: list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>, code: string}>  $records
     * @return list<CartNoticeLine>
     */
    private static function notice(Cart $cart, array $records, string $locale): array
    {
        $lines = [];

        foreach ($cart->notice as $removed) {
            $record = $records[$removed->variantId] ?? null;
            $reason = CartNoticeReason::tryFrom($removed->reason);

            if ($record !== null && $reason !== null) {
                $lines[] = new CartNoticeLine($removed->variantId, self::name($record, $locale), $record['code'], $record['values'], $removed->mode->value, $removed->quantity, $reason);
            }
        }

        return $lines;
    }

    /**
     * @param  array{nameAr: string, nameEn: string}  $record
     */
    private static function name(array $record, string $locale): string
    {
        return $locale === 'ar' ? $record['nameAr'] : $record['nameEn'];
    }
}
