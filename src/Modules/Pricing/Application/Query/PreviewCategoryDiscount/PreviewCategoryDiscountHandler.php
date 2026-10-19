<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PreviewCategoryDiscount;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\DiscountInput;
use Modules\Pricing\Application\Prices\DiscountPreviewer;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Prices\SaleInput;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\CategoryNotUsable;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Application\Unauthorized;

/**
 * **The preview before saving** (`PreviewCategoryDiscount`, pricing.md §1.5, §3, §4.3.3 P3), under **Manage
 * Category Discounts** in that store: the discount as the form holds it, read as adding it would - its
 * category there and on, a percentage or an amount, its lines, the products it leaves out (in the category,
 * Q10) - and what it would do, its products 20 at a time. A read: no lock, nothing written; the checks
 * that refuse it run when it is saved.
 */
final readonly class PreviewCategoryDiscountHandler
{
    public function __construct(
        private ReadAccess $access,
        private DiscountInput $input,
        private DiscountPreviewer $previewer,
        private CatalogApi $catalog,
        private CategoryDiscounts $discounts,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|CategoryNotUsable
     */
    public function handle(PreviewCategoryDiscount $query): DiscountPreview
    {
        $store = $this->access->authorize($query->storeId, [PricingPermissions::CATEGORY_DISCOUNT_MANAGE]);
        $categoryId = strtolower(trim($query->categoryId));
        $category = $this->catalog->category($categoryId);

        if ($category === null || ! $category->isActive) {
            throw new CategoryNotUsable;
        }

        if (($query->percent === null) === ($query->amount === null)) {
            throw new InvalidPricingInput($query->percent === null ? 'amount' : 'percent');
        }

        $now = CarbonImmutable::now()->toDateTimeImmutable();
        // Being changed: the products it leaves out already stay allowed, as the change allows them.
        $before = $query->discountId === null ? null : $this->discounts->find($query->discountId);

        return $this->previewer->preview($store, new CategoryDiscount(
            // Its own id when it is being changed, so it is not its own overlap.
            $query->discountId === null ? 'preview' : strtolower(trim($query->discountId)),
            $store->id,
            $categoryId,
            $query->percent === null ? null : SaleInput::percent($query->percent),
            $query->amount === null ? null : AmountInput::of($query->amount, $store),
            DiscountInput::lines($query->lines),
            SaleInput::start($query->startsAt, $now),
            SaleInput::end($query->endsAt),
            false,
            $this->input->leftOut($query->leftOut, $categoryId, $before !== null && $before->storeId === $store->id ? $before->excludedProductIds : []),
        ), $query->after);
    }
}
