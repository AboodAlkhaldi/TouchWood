<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewCategoryDiscount;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\DiscountPreviewer;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Query\ListCategoryDiscounts\ListCategoryDiscountsHandler;
use Modules\Pricing\Domain\Exception\CategoryDiscountNotFound;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Shared\Application\Unauthorized;

/**
 * **A category discount's page** (`ViewCategoryDiscount`, pricing.md §3), for whoever holds any of
 * Pricing's three jobs in its store (owner, 2026-10-08): the discount, and its preview over what is in
 * its category now. A read: no lock, nothing written.
 */
final readonly class ViewCategoryDiscountHandler
{
    public function __construct(
        private ReadAccess $access,
        private CategoryDiscounts $discounts,
        private DiscountPreviewer $previewer,
    ) {}

    /**
     * @throws CategoryDiscountNotFound|Unauthorized|InvalidPricingInput
     */
    public function handle(ViewCategoryDiscount $query): CategoryDiscountView
    {
        $discount = $this->discounts->find($query->discountId) ?? throw new CategoryDiscountNotFound;
        $store = $this->access->authorize($discount->storeId)->storeId();

        return new CategoryDiscountView(
            ListCategoryDiscountsHandler::row($discount, CarbonImmutable::now()->toDateTimeImmutable()),
            $this->previewer->preview($store, $discount),
        );
    }
}
