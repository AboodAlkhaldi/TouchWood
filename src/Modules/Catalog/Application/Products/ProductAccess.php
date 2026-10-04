<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who may change a product's shared data (catalog.md §1.1, §3), asked before anything about it is
 * read. Creating one is checked in the creator's working store, by its handler.
 *
 * - **Its shared data** — details, variants, codes, gallery and the rest — needs the permission **in
 *   every store where the product is Active** (any of its variants switched on there); a product
 *   Active nowhere may be changed by anyone holding the permission in some store (§1.1). Asked twice:
 *   for "some store" before anything is read, then — inside the change, once the product's row is
 *   locked and so its stores read under the products' lock — in each store that sells it.
 */
final readonly class ProductAccess
{
    public function __construct(
        private Authorizer $authorizer,
        private StoreListingRepository $listings,
    ) {}

    /**
     * The permission in some store, for a product Active nowhere: one real check, against all
     * stores for someone holding it everywhere, else against one store they hold it in.
     *
     * @throws Unauthorized
     */
    public function authorize(string $permission): void
    {
        $stores = $this->authorizer->storesWith($permission);

        if ($stores === []) {
            throw new Unauthorized($permission);
        }

        $this->authorizer->authorize($permission, $stores === null ? PermissionScope::allStores() : PermissionScope::store($stores[0]));
    }

    /**
     * The permission in every store where the product is Active — asked inside its change, after
     * the product's row is locked. A product Active nowhere needs nothing more than "some store".
     *
     * @throws Unauthorized
     */
    public function authorizeFor(string $permission, string $productId): void
    {
        foreach ($this->listings->activeStoresOf($productId) as $storeId) {
            $this->authorizer->authorize($permission, PermissionScope::store(StoreId::fromString($storeId)));
        }
    }
}
