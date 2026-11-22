<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\CategoryChoices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\CategoryPathDto;
use Modules\Pricing\Application\Prices\ReachViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Shared\Application\Unauthorized;

/**
 * **The discount dialog's categories** (`CategoryChoices`, pricing.md §4.3.3 P3; stage 5's screens step 3),
 * for whoever manages category discounts in the store: Catalog's categories switched on, each with its path
 * (`activeCategories`, catalog.md amendment 23(f)), by path in the page's language. The asking and two
 * queries. A read: nothing written.
 */
final readonly class CategoryChoicesHandler
{
    public function __construct(
        private ReadAccess $access,
        private CatalogApi $catalog,
        private ReachViews $reach,
    ) {}

    /**
     * @return list<CategoryChoice>
     *
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(CategoryChoices $query): array
    {
        $this->access->authorize($query->storeId, [PricingPermissions::CATEGORY_DISCOUNT_MANAGE]);
        $choices = array_map(fn (CategoryPathDto $category): CategoryChoice => new CategoryChoice($category->id, $this->reach->path($category)), $this->catalog->activeCategories());
        usort($choices, static fn (CategoryChoice $a, CategoryChoice $b): int => strcmp(implode(' › ', $a->path), implode(' › ', $b->path)));

        return $choices;
    }
}
