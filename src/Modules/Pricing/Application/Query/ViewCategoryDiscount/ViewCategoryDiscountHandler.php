<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewCategoryDiscount;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\DiscountPreviewer;
use Modules\Pricing\Application\Prices\DiscountRows;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\CategoryDiscountNotFound;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Shared\Application\Unauthorized;

/**
 * **A category discount's page** (`ViewCategoryDiscount`, pricing.md §3, §4.3.3 P3), for whoever holds any
 * of Pricing's three jobs in its store (owner, 2026-10-08): the discount as the list shows it, and its
 * preview over what is in its category now. A read: no lock, nothing written.
 */
final readonly class ViewCategoryDiscountHandler
{
    public function __construct(
        private ReadAccess $access,
        private CategoryDiscounts $discounts,
        private DiscountPreviewer $previewer,
        private DiscountRows $rows,
    ) {}

    /**
     * @throws CategoryDiscountNotFound|Unauthorized|InvalidPricingInput
     */
    public function handle(ViewCategoryDiscount $query): CategoryDiscountView
    {
        // Its store first, then the reader's permission there, then the discount (as `ViewSale`).
        $store = $this->access->authorize($this->discounts->storeOf($query->discountId) ?? throw new CategoryDiscountNotFound);
        $discount = $this->discounts->find($query->discountId) ?? throw new CategoryDiscountNotFound;

        return new CategoryDiscountView(
            $this->rows->rows([$discount], $store, CarbonImmutable::now()->toDateTimeImmutable())[0],
            $this->previewer->preview($store, $discount, $query->after),
        );
    }
}
