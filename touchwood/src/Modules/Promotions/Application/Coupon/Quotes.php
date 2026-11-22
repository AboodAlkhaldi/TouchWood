<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Coupon;

use Carbon\CarbonImmutable;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Model\CouponOutcome;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Domain\Service\CouponRules;
use Modules\Promotions\Domain\Service\DiscountRoom;
use Modules\Promotions\Public\Enums\Audience;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a coupon takes off a cart as it is now (promotions.md §1.3): the same answer for a quote
 * (`applyCoupon`) and for placing the order (`useCoupon`), which asks every question again — the
 * customer's uses and assignment, the cart's lines, the room under the store's ceiling, the time.
 */
final readonly class Quotes
{
    public function __construct(
        private Coupons $coupons,
        private CartLines $lines,
        private Ceiling $ceiling,
    ) {}

    public function outcome(Coupon $coupon, StoreId $store, string $customerId, Audience $audience, CartPricesDto $prices): CouponOutcome
    {
        self::sameCurrency($coupon, $prices->currencyCode, 'the prices');

        return CouponRules::quote(
            $coupon,
            $this->coupons->usage($store, $coupon->id, $customerId),
            $this->lines->of($prices),
            $audience,
            $prices->netSubtotal,
            $this->room($store, $prices->grossSubtotal, $prices->netSubtotal),
            CarbonImmutable::now(),
        );
    }

    /** The room left under the store's ceiling of this moment (§1.6). */
    public function room(StoreId $store, Money $grossSubtotal, Money $netSubtotal): Money
    {
        return DiscountRoom::of($grossSubtotal, $netSubtotal, $this->ceiling->percentIn($store));
    }

    /** Prices of another store are the caller's mistake, refused before any coupon is read. */
    public static function sameStore(StoreId $store, CartPricesDto $prices): void
    {
        if (strtolower($prices->storeId) !== $store->value) {
            throw new InvalidPromotionAttribute('store', 'the prices are another store\'s');
        }
    }

    /** An amount in another currency than the coupon's is the caller's mistake. */
    public static function sameCurrency(Coupon $coupon, string $currencyCode, string $what): void
    {
        if ($currencyCode !== $coupon->currencyCode) {
            throw new InvalidPromotionAttribute('currency', "{$what} are in {$currencyCode}, the coupon in {$coupon->currencyCode}");
        }
    }
}
