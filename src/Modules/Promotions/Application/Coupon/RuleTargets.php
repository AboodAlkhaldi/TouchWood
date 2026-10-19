<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Coupon;

use Illuminate\Database\QueryException;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\CouponRule;
use Modules\Promotions\Domain\Model\RuleTarget;

/**
 * What a coupon's include and exclude lists may name (promotions.md §1.2): **products that are ready,
 * active categories and brands** — through Catalog's public reads, one call for each kind whatever
 * the list's length (handoff §5.4): `products`, `categoriesWithPaths` (catalog.md amendment 23(c)),
 * `brands` (amendment 27).
 */
final readonly class RuleTargets
{
    public function __construct(
        private CatalogApi $catalog,
    ) {}

    /**
     * A list entry Catalog deleted between the check and the write (a category or brand: Catalog deletes
     * only draft products, never ready ones) breaks the rule's foreign key: answered as the check would
     * have, never as a database error. Anything else is not ours to name.
     */
    public static function removedMeanwhile(QueryException $failed): InvalidPromotionAttribute|QueryException
    {
        return $failed->getCode() === '23503' && preg_match('/\bcoupon_rules_(product|category|brand)\b/', $failed->getPrevious()?->getMessage() ?? $failed->getMessage()) === 1
            ? new InvalidPromotionAttribute('rules', 'an entry was removed from the catalog meanwhile')
            : $failed;
    }

    /**
     * @param  list<CouponRule>  $rules
     *
     * @throws InvalidPromotionAttribute
     */
    public function check(array $rules): void
    {
        $productIds = array_values(array_map(static fn (CouponRule $rule): string => $rule->targetId, array_filter($rules, static fn (CouponRule $rule): bool => $rule->target === RuleTarget::Product)));
        $products = $productIds === [] ? [] : $this->catalog->products($productIds);
        $brandIds = array_values(array_map(static fn (CouponRule $rule): string => $rule->targetId, array_filter($rules, static fn (CouponRule $rule): bool => $rule->target === RuleTarget::Brand)));
        $brands = $brandIds === [] ? [] : $this->catalog->brands($brandIds);
        $categoryIds = array_values(array_map(static fn (CouponRule $rule): string => $rule->targetId, array_filter($rules, static fn (CouponRule $rule): bool => $rule->target === RuleTarget::Category)));
        $categories = $categoryIds === [] ? [] : $this->catalog->categoriesWithPaths($categoryIds);

        foreach ($rules as $rule) {
            match ($rule->target) {
                RuleTarget::Product => ($products[$rule->targetId] ?? null)?->stage === ProductStage::Ready
                    ?: throw new InvalidPromotionAttribute('product', "{$rule->targetId} is not a ready product"),
                RuleTarget::Category => ($categories[$rule->targetId] ?? null)?->isActive
                    ?: throw new InvalidPromotionAttribute('category', "{$rule->targetId} is not an active category"),
                RuleTarget::Brand => ($brands[$rule->targetId] ?? null)?->isActive
                    ?: throw new InvalidPromotionAttribute('brand', "{$rule->targetId} is not an active brand"),
            };
        }
    }
}
