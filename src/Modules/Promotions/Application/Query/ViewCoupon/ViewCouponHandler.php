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
 * not found; its lists named through Catalog in one call for each kind — products, categories
 * (catalog.md amendment 23(c)), brands (amendment 27).
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
        $brandIds = array_values(array_map(static fn (CouponRule $rule): string => $rule->targetId, array_filter($coupon->rules, static fn (CouponRule $rule): bool => $rule->target === RuleTarget::Brand)));
        $brands = $brandIds === [] ? [] : $this->catalog->brands($brandIds);
        $categoryIds = array_values(array_map(static fn (CouponRule $rule): string => $rule->targetId, array_filter($coupon->rules, static fn (CouponRule $rule): bool => $rule->target === RuleTarget::Category)));
        $categories = $categoryIds === [] ? [] : $this->catalog->categoriesWithPaths($categoryIds);

        $rules = array_map(static function (CouponRule $rule) use ($products, $brands, $categories): RuleView {
            $named = match ($rule->target) {
                RuleTarget::Product => $products[$rule->targetId] ?? null,
                RuleTarget::Category => $categories[$rule->targetId] ?? null,
                RuleTarget::Brand => $brands[$rule->targetId] ?? null,
            };

            return new RuleView($rule->kind->value, $rule->target->value, $rule->targetId, $named?->nameAr, $named?->nameEn);
        }, $coupon->rules);

        return new CouponView(
            $row,
            $coupon->cap,
            $coupon->minimum,
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
