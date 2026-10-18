<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ViewCoupon;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Promotions\Application\Query\CouponView;
use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Application\Query\MarketingReader;
use Modules\Promotions\Application\Query\RuleView;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Model\CouponRule;
use Modules\Promotions\Domain\Model\RuleTarget;
use Modules\Promotions\Domain\Repository\Coupons;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * One coupon in full (promotions.md §3): its store covered by one of the four permissions, or it is
 * not found; its lists named through Catalog — the products in one call, a category or brand one at a
 * time as Catalog offers them (a list is a handful).
 */
final readonly class ViewCouponHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private MarketingReader $reader,
        private Coupons $coupons,
        private MarketingQueries $queries,
        private CatalogApi $catalog,
    ) {}

    /**
     * @throws Unauthorized|CouponNotFound
     */
    public function handle(ViewCoupon $query): CouponView
    {
        [$store, $permission] = $this->reader->covered(fn () => $this->coupons->storeOf($query->couponId), static fn (): CouponNotFound => new CouponNotFound);
        $this->authorizer->authorize($permission, PermissionScope::store($store));
        $this->reader->open($store);
        $coupon = $this->coupons->byId($store, strtolower($query->couponId)) ?? throw new CouponNotFound;
        $row = $this->queries->couponRow($store, $coupon->id, CarbonImmutable::now()) ?? throw new CouponNotFound;

        $productIds = array_values(array_map(static fn (CouponRule $rule): string => $rule->targetId, array_filter($coupon->rules, static fn (CouponRule $rule): bool => $rule->target === RuleTarget::Product)));
        $products = $productIds === [] ? [] : $this->catalog->products($productIds);

        $rules = array_map(function (CouponRule $rule) use ($products): RuleView {
            $named = match ($rule->target) {
                RuleTarget::Product => $products[$rule->targetId] ?? null,
                RuleTarget::Category => $this->catalog->category($rule->targetId),
                RuleTarget::Brand => $this->catalog->brand($rule->targetId),
            };

            return new RuleView($rule->kind->value, $rule->target->value, $rule->targetId, $named?->nameAr, $named?->nameEn);
        }, $coupon->rules);

        return new CouponView(
            $row,
            $coupon->cap?->minorUnits,
            $coupon->minimum?->minorUnits,
            $coupon->maxUsesPerCustomer,
            $coupon->allowWithPoints,
            $coupon->onReducedLines,
            $coupon->forRetail,
            $coupon->forWholesale,
            $coupon->forPublic,
            $coupon->forCompany,
            $rules,
            $this->queries->assignedCount($coupon->id),
        );
    }
}
