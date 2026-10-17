<?php

declare(strict_types=1);

namespace Modules\Promotions\Application;

use Carbon\CarbonImmutable;
use Modules\Promotions\Application\Coupon\CartLines;
use Modules\Promotions\Application\Coupon\Ceiling;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Domain\Service\CouponRules;
use Modules\Promotions\Domain\Service\DiscountRoom;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\CouponResult;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * promotions.md §2.1. The reads: no lock, no transaction — each answers one question about one moment.
 */
final readonly class PromotionsApiImpl implements PromotionsApi
{
    public function __construct(
        private Coupons $coupons,
        private CartLines $lines,
        private Ceiling $ceiling,
    ) {}

    public function applyCoupon(CouponRequest $request): CouponResult
    {
        $store = Inputs::store($request->storeId);
        $customerId = Inputs::id($request->customerId, 'customer');

        if (strtolower($request->prices->storeId) !== $store->value) {
            throw new InvalidPromotionAttribute('store', 'the prices are another store\'s');
        }

        $code = Inputs::code($request->code);
        $coupon = $code === null ? null : $this->coupons->byCode($store, $code);

        if ($coupon === null) {
            return CouponResult::refused(CouponRefusal::UnknownCode);
        }

        if ($request->prices->currencyCode !== $coupon->currencyCode) {
            throw new InvalidPromotionAttribute('currency', "the prices are in {$request->prices->currencyCode}, the coupon in {$coupon->currencyCode}");
        }

        $outcome = CouponRules::quote(
            $coupon,
            $this->coupons->usage($store, $coupon->id, $customerId),
            $this->lines->of($request->prices),
            $request->audience,
            $request->prices->netSubtotal,
            $this->customerDiscountRoom($store, $request->prices->grossSubtotal, $request->prices->netSubtotal),
            CarbonImmutable::now(),
        );

        return $outcome->refusal !== null || $outcome->discount === null
            ? CouponResult::refused($outcome->refusal ?? CouponRefusal::WorthNothing, $coupon->id, $coupon->nameAr, $coupon->nameEn)
            : CouponResult::taken($coupon->id, $coupon->nameAr, $coupon->nameEn, $outcome->discount, $outcome->shares, $coupon->allowWithPoints);
    }

    public function customerDiscountRoom(StoreId $store, Money $grossSubtotal, Money $netSubtotal): Money
    {
        return DiscountRoom::of($grossSubtotal, $netSubtotal, $this->ceiling->percentIn($store));
    }
}
