<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewCategoryDiscount;

use Carbon\CarbonImmutable;
use Modules\Pricing\Application\Prices\DiscountRows;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\CategoryDiscountNotFound;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Shared\Application\Unauthorized;

/**
 * **A category discount's fields** (`ViewDiscountFields`), for whoever may read its store's discounts: the
 * discount, then the reader's permission in its store, then its row as the list shows it - the category's
 * path, the products left out named. A few queries, no preview. A read: nothing written.
 */
final readonly class ViewDiscountFieldsHandler
{
    public function __construct(
        private ReadAccess $access,
        private CategoryDiscounts $discounts,
        private DiscountRows $rows,
    ) {}

    /**
     * @throws CategoryDiscountNotFound|Unauthorized|InvalidPricingInput
     */
    public function handle(ViewDiscountFields $query): DiscountFields
    {
        $discount = $this->discounts->find($query->discountId) ?? throw new CategoryDiscountNotFound;
        $store = $this->access->authorize($discount->storeId);

        return new DiscountFields($store->id, $this->rows->rows([$discount], $store, CarbonImmutable::now()->toDateTimeImmutable())[0]);
    }
}
