<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListCategories;

use InvalidArgumentException;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\ListReaders;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The categories screen's read** (catalog.md §4.4 S2): anyone holding `catalog.category.manage` or
 * `catalog.category.rank` in some store reads the tree (P2). A store asked for is one where the
 * reader orders the menu — any other is refused as not allowed, as a store anyone names is (§7) —
 * and its order is shown beside the base store's, which a store's admins have not changed yet stands
 * for (amendment 5(a)).
 */
final readonly class ListCategoriesHandler
{
    /** Every job that reads the tree. */
    public const array JOBS = [CatalogPermissions::CATEGORY_MANAGE, CatalogPermissions::CATEGORY_RANK];

    public function __construct(
        private ListReaders $readers,
        private Authorizer $authorizer,
        private CatalogListReads $reads,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListCategories $query): CategoryList
    {
        $mayManage = $this->readers->authorize(...self::JOBS);
        $store = self::store($query->storeId);
        $mayRank = $store !== null && $this->ranksIn($store);

        if ($store !== null && ! $mayRank) {
            throw new Unauthorized(CatalogPermissions::CATEGORY_RANK);
        }

        // One read of the stores: the one asked for must be one, and the base store's place is shown
        // beside it.
        $base = null;
        $found = $store === null;

        foreach ($this->platform->allStores() as $candidate) {
            $base = $candidate->isBase ? $candidate->id : $base;

            if ($store !== null && $store->equals(StoreId::fromString($candidate->id))) {
                $found = true;
            }
        }

        // A store that does not exist is refused as one the reader does not order: a filter tells
        // nobody which stores there are.
        if (! $found) {
            throw new Unauthorized(CatalogPermissions::CATEGORY_RANK);
        }

        return new CategoryList($this->reads->categories($store?->value, $base), $store?->value, $mayManage, $mayRank);
    }

    /** Whether the reader orders this store's menu; null from storesWith() is every store. */
    private function ranksIn(StoreId $store): bool
    {
        $stores = $this->authorizer->storesWith(CatalogPermissions::CATEGORY_RANK);

        if ($stores === null) {
            return true;
        }

        foreach ($stores as $each) {
            if ($each->equals($store)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws Unauthorized for a store id that is not one
     */
    private static function store(?string $storeId): ?StoreId
    {
        if ($storeId === null || trim($storeId) === '') {
            return null;
        }

        try {
            return StoreId::fromString(trim($storeId));
        } catch (InvalidArgumentException) {
            throw new Unauthorized(CatalogPermissions::CATEGORY_RANK);
        }
    }
}
