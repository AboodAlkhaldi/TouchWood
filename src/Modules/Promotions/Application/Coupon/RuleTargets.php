<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Coupon;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\CouponRule;
use Modules\Promotions\Domain\Model\RuleTarget;

/**
 * What a coupon's include and exclude lists may name (promotions.md §1.2): **products that are ready,
 * active categories and brands** — through Catalog's public reads. The products are read in one call;
 * a category or brand one at a time, as Catalog offers them (a list is a handful).
 */
final readonly class RuleTargets
{
    public function __construct(
        private CatalogApi $catalog,
    ) {}

    /**
     * @param  list<CouponRule>  $rules
     *
     * @throws InvalidPromotionAttribute
     */
    public function check(array $rules): void
    {
        $productIds = array_values(array_map(static fn (CouponRule $rule): string => $rule->targetId, array_filter($rules, static fn (CouponRule $rule): bool => $rule->target === RuleTarget::Product)));
        $products = $productIds === [] ? [] : $this->catalog->products($productIds);

        foreach ($rules as $rule) {
            match ($rule->target) {
                RuleTarget::Product => ($products[$rule->targetId] ?? null)?->stage === ProductStage::Ready
                    ?: throw new InvalidPromotionAttribute('product', "{$rule->targetId} is not a ready product"),
                RuleTarget::Category => $this->catalog->category($rule->targetId)?->isActive
                    ?: throw new InvalidPromotionAttribute('category', "{$rule->targetId} is not an active category"),
                RuleTarget::Brand => $this->catalog->brand($rule->targetId)?->isActive
                    ?: throw new InvalidPromotionAttribute('brand', "{$rule->targetId} is not an active brand"),
            };
        }
    }
}
