<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Products;

use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Who may change a product's shared data (catalog.md §1.1, §3), asked before anything about it is
 * read. Creating one is checked in the creator's working store, by its handler.
 *
 * - **Its shared data** — details, variants, codes, gallery and the rest — needs the permission **in
 *   every store where the product is Active**; a product Active nowhere may be changed by anyone
 *   holding the permission in some store (§1.1). Stores choose products from step 4, so until then
 *   every product is Active nowhere, and this asks only for "some store"; step 4 adds the stores
 *   that sell it.
 */
final readonly class ProductAccess
{
    public function __construct(
        private Authorizer $authorizer,
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
}
