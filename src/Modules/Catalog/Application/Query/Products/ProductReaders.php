<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

use Modules\Catalog\Application\CatalogPermissions;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * **Who may read the products, and what they may do to one** (catalog.md §3, §4.4 S8, S9) — the same
 * answers the product handlers give (`ProductAccess`), asked rather than thrown, so a page shows each
 * button out of reach with its reason instead of refusing after it is pressed:
 *
 * - a product's shared data — its details, variants, photos, words, filters, relations — needs the
 *   job in **every store where the product is on**; a product on nowhere, in some store;
 * - making ready, restoring and deleting a draft reach only products on nowhere: the job in some store.
 *
 * One read of each job asked, so a page asks the authorizer once a job (frontend.md §5).
 */
final readonly class ProductReaders
{
    public function __construct(
        private Authorizer $authorizer,
    ) {}

    /**
     * The stores where the reader reads the products: null for every store.
     *
     * @return list<string>|null store ids
     *
     * @throws Unauthorized when the reader reads them nowhere
     */
    public function covered(): ?array
    {
        $stores = $this->authorizer->storesWith(CatalogPermissions::PRODUCT_VIEW);

        if ($stores === []) {
            throw new Unauthorized(CatalogPermissions::PRODUCT_VIEW);
        }

        return $stores === null ? null : array_map(static fn ($store): string => $store->value, $stores);
    }

    /**
     * Whether the reader holds the job for this product: in every store where it is on, or - on
     * nowhere - in some store.
     *
     * @param  list<string>  $onIn  the stores where the product is on, as ids
     */
    public function may(string $permission, array $onIn): bool
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === null) {
            return true;
        }

        if ($stores === []) {
            return false;
        }

        $held = [];

        foreach ($stores as $store) {
            $held[$store->value] = true;
        }

        foreach ($onIn as $storeId) {
            if (! isset($held[strtolower($storeId)])) {
                return false;
            }
        }

        return true;
    }
}
