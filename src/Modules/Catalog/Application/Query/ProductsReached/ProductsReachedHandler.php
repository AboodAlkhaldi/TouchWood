<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ProductsReached;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\ReachedProductRow;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **What a deactivation would reach** (catalog.md §1.5, §1.6): every product carrying the brand, or in
 * the category and under it — in any stage (amendment 4(d)) — for the dialog where each is given its
 * fate. Only someone who may deactivate reads it: the list's job with All stores. The deactivation
 * reads them again, under its locks; this is what the dialog shows.
 */
final readonly class ProductsReachedHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private CatalogListReads $reads,
    ) {}

    /**
     * @return list<ReachedProductRow>
     *
     * @throws InvalidCatalogAttribute|Unauthorized
     */
    public function handle(ProductsReached $query): array
    {
        return match ($query->kind) {
            ProductsReached::BRAND => $this->brand($query->id),
            ProductsReached::CATEGORY => $this->category($query->id),
            default => throw new InvalidCatalogAttribute('kind', 'brand or category'),
        };
    }

    /**
     * @return list<ReachedProductRow>
     */
    private function brand(string $brandId): array
    {
        $this->authorizer->authorize(CatalogPermissions::BRAND_MANAGE, PermissionScope::allStores());

        return $this->reads->productsOfBrand($brandId);
    }

    /**
     * @return list<ReachedProductRow>
     */
    private function category(string $categoryId): array
    {
        $this->authorizer->authorize(CatalogPermissions::CATEGORY_MANAGE, PermissionScope::allStores());

        return $this->reads->productsUnderCategory($categoryId);
    }
}
