<?php

declare(strict_types=1);

namespace Modules\Promotions\Application;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Promotions\Application\Command\RecordOrder\RecordOrderCommand;
use Modules\Promotions\Application\Command\RecordOrder\RecordOrderHandler;
use Modules\Promotions\Application\Command\UpdateCouponUse\UpdateCouponUseCommand;
use Modules\Promotions\Application\Command\UpdateCouponUse\UpdateCouponUseHandler;
use Modules\Promotions\Application\Command\UseCoupon\UseCouponCommand;
use Modules\Promotions\Application\Command\UseCoupon\UseCouponHandler;
use Modules\Promotions\Application\Coupon\CartLines;
use Modules\Promotions\Application\Coupon\Inputs;
use Modules\Promotions\Application\Coupon\Quotes;
use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Domain\Model\GiftLevel;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Domain\Repository\GiftLevels;
use Modules\Promotions\Domain\Repository\OrderFacts;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Domain\Service\CouponRules;
use Modules\Promotions\Domain\Service\DiscountRoom;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\CouponResult;
use Modules\Promotions\Public\Dto\CouponsUsedDto;
use Modules\Promotions\Public\Dto\EditedCouponRequest;
use Modules\Promotions\Public\Dto\GiftOfferDto;
use Modules\Promotions\Public\Dto\RecordOrder;
use Modules\Promotions\Public\Dto\UpdateCouponUse;
use Modules\Promotions\Public\Dto\UseCoupon;
use Modules\Promotions\Public\Enums\Audience;
use Modules\Promotions\Public\Enums\CouponEligibility;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * promotions.md §2.1. The reads: no lock, no transaction — each answers one question about one moment.
 * The writes go through their command handlers, which check the caller's permission.
 */
final readonly class PromotionsApiImpl implements PromotionsApi
{
    public function __construct(
        private Coupons $coupons,
        private OrderFacts $facts,
        private CartLines $lines,
        private Quotes $quotes,
        private UseCouponHandler $use,
        private RecordOrderHandler $record,
        private UpdateCouponUseHandler $update,
        private PlatformApi $platform,
        private Segments $segments,
        private GiftLevels $levels,
        private MarketingQueries $queries,
    ) {}

    public function applyCoupon(CouponRequest $request): CouponResult
    {
        $store = Inputs::store($request->storeId);
        $customerId = Inputs::id($request->customerId, 'customer');
        Quotes::sameStore($store, $request->prices);

        $code = Inputs::code($request->code);
        $coupon = $code === null ? null : $this->coupons->byCode($store, $code);

        if ($coupon === null) {
            return CouponResult::refused(CouponRefusal::UnknownCode);
        }

        $outcome = $this->quotes->outcome($coupon, $store, $customerId, $request->audience, $request->prices);

        // A coupon given to others is no coupon to this customer: no reason, no name (answer 29).
        if ($outcome->refusal === CouponRefusal::NotAssignedToYou) {
            return CouponResult::refused(CouponRefusal::UnknownCode);
        }

        return $outcome->refusal !== null || $outcome->discount === null
            ? CouponResult::refused($outcome->refusal ?? CouponRefusal::WorthNothing, $coupon->id, $coupon->nameAr, $coupon->nameEn)
            : CouponResult::taken($coupon->id, $coupon->nameAr, $coupon->nameEn, $outcome->discount, $outcome->shares, $coupon->allowWithPoints);
    }

    public function knowsCoupon(StoreId $store, string $customerId, string $code): bool
    {
        $customerId = Inputs::id($customerId, 'customer');
        $code = Inputs::code($code);
        $coupon = $code === null ? null : $this->coupons->byCode($store, $code);

        return $coupon !== null
            && ($coupon->eligibility !== CouponEligibility::Assigned || $this->coupons->usage($store, $coupon->id, $customerId)->assigned);
    }

    public function customerDiscountRoom(StoreId $store, Money $grossSubtotal, Money $netSubtotal): Money
    {
        return $this->quotes->room($store, $grossSubtotal, $netSubtotal);
    }

    public function useCoupon(UseCoupon $change): void
    {
        $this->use->handle(new UseCouponCommand($change));
    }

    public function recordOrder(RecordOrder $change): void
    {
        $this->record->handle(new RecordOrderCommand($change));
    }

    public function orderDiscountRoom(string $orderId, Money $grossSubtotal, Money $netSubtotal): Money
    {
        $fact = $this->facts->of(Inputs::id($orderId, 'order')) ?? throw new InvalidPromotionAttribute('order', 'no such order recorded');
        $currency = $this->platform->store(StoreId::fromString($fact->storeId))->currencyCode ?? null;

        if ($grossSubtotal->currencyCode !== $currency || $netSubtotal->currencyCode !== $currency) {
            throw new InvalidPromotionAttribute('currency', "the subtotals are not in the order's store's currency ({$currency})");
        }

        return DiscountRoom::of($grossSubtotal, $netSubtotal, $fact->ceilingPercent);
    }

    public function couponAfterEdit(EditedCouponRequest $request): CouponResult
    {
        $orderId = Inputs::id($request->orderId, 'order');
        $couponId = Inputs::id($request->couponId, 'coupon');
        $use = $this->coupons->useOf($orderId) ?? throw new InvalidPromotionAttribute('order', 'the order used no coupon');

        if ($use->couponId !== $couponId) {
            throw new InvalidPromotionAttribute('coupon', 'not the order\'s coupon');
        }

        $store = StoreId::fromString($use->storeId);
        Quotes::sameStore($store, $request->prices);
        $coupon = $this->coupons->byId($store, $couponId) ?? throw new InvalidPromotionAttribute('coupon', 'no such coupon');
        Quotes::sameCurrency($coupon, $request->prices->currencyCode, 'the prices');
        Quotes::sameCurrency($coupon, $request->room->currencyCode, 'the room');
        Quotes::sameCurrency($coupon, $request->discountNow->currencyCode, 'the discount now');

        // As the coupon is now, but its dates, switch, uses and assignment were settled at placement.
        $outcome = CouponRules::work($coupon, $this->lines->of($request->prices), $request->audience, $request->prices->netSubtotal);

        if ($outcome->refusal !== null || $outcome->discount === null) {
            return CouponResult::refused($outcome->refusal ?? CouponRefusal::WorthNothing, $coupon->id, $coupon->nameAr, $coupon->nameEn);
        }

        // Never more than it is now — the recorded use is the truth, the caller's figure may only lower
        // it — then trimmed to the room only if it alone passes it (the points gave way first, §1.3).
        $now = min($use->discountMinor, $request->discountNow->minorUnits);
        $most = min($now, $request->room->minorUnits);

        if ($most <= 0) {
            return CouponResult::refused($now <= 0 ? CouponRefusal::WorthNothing : CouponRefusal::PassesCeiling, $coupon->id, $coupon->nameAr, $coupon->nameEn);
        }

        if ($outcome->discount->minorUnits <= $most) {
            return CouponResult::taken($coupon->id, $coupon->nameAr, $coupon->nameEn, $outcome->discount, $outcome->shares, $coupon->allowWithPoints);
        }

        $trimmed = Money::of($most, $coupon->currencyCode);
        $shares = $trimmed->allocate(...array_map(static fn (Money $share): int => $share->minorUnits, $outcome->shares));

        return CouponResult::taken($coupon->id, $coupon->nameAr, $coupon->nameEn, $trimmed, $shares, $coupon->allowWithPoints);
    }

    public function updateCouponUse(UpdateCouponUse $change): void
    {
        $this->update->handle(new UpdateCouponUseCommand($change));
    }

    public function segmentMembers(string $segmentId, ?string $after, int $limit): array
    {
        $segmentId = Inputs::id($segmentId, 'segment');

        if ($limit < 1 || $limit > 1000) {
            throw new InvalidPromotionAttribute('limit', 'from 1 to 1000');
        }

        if ($this->segments->storeOf($segmentId) === null) {
            throw new SegmentNotFound;
        }

        return $this->segments->membersPage($segmentId, $after === null ? null : Inputs::id($after, 'after'), $limit);
    }

    public function giftLevels(StoreId $store, Audience $audience, Money $goodsTotal): array
    {
        $this->inStoreCurrency($store, $goodsTotal);

        return self::offers($this->levels->reached($store, $audience, $goodsTotal->minorUnits, CarbonImmutable::now()));
    }

    public function giftLevelsAbove(StoreId $store, Audience $audience, Money $goodsTotal): array
    {
        $this->inStoreCurrency($store, $goodsTotal);

        return self::offers($this->levels->above($store, $audience, $goodsTotal->minorUnits, CarbonImmutable::now()));
    }

    public function couponsUsed(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): CouponsUsedDto
    {
        $currency = $this->platform->store($store)->currencyCode ?? throw new InvalidPromotionAttribute('store', 'no such store');
        [$uses, $discount] = $from < $to ? $this->queries->usesIn($store, $from, $to) : [0, 0];

        return new CouponsUsedDto($uses, Money::of($discount, $currency));
    }

    public function assignedCustomers(string $assignmentId, ?string $after, int $limit): array
    {
        $assignmentId = Inputs::id($assignmentId, 'assignment');

        if ($limit < 1 || $limit > 1000) {
            throw new InvalidPromotionAttribute('limit', 'from 1 to 1000');
        }

        return $this->queries->assignedBy($assignmentId, $after === null ? null : Inputs::id($after, 'after'), $limit);
    }

    private function inStoreCurrency(StoreId $store, Money $goodsTotal): void
    {
        $currency = $this->platform->store($store)->currencyCode ?? throw new InvalidPromotionAttribute('store', 'no such store');

        if ($goodsTotal->currencyCode !== $currency) {
            throw new InvalidPromotionAttribute('currency', "the goods total is not in the store's currency ({$currency})");
        }
    }

    /**
     * @param  list<GiftLevel>  $levels
     * @return list<GiftOfferDto>
     */
    private static function offers(array $levels): array
    {
        return array_map(static fn (GiftLevel $level): GiftOfferDto => new GiftOfferDto($level->id, Money::of($level->amountMinor, $level->currencyCode), $level->variantId), $levels);
    }
}
