<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\RankCategories;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\ValueObject\ListPosition;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A store's order of its menu** (catalog.md §1.5, §3), under `catalog.category.rank` in that
 * store: the tree is every store's, the order each store's own. Under the categories' lock, so a
 * category deleted meanwhile is not placed. Each category whose place changed is audited in that
 * store.
 */
final readonly class RankCategoriesHandler
{
    public const string PERMISSION = CatalogPermissions::CATEGORY_RANK;

    /** More than any menu holds; a request beyond it is refused before anything is read. */
    public const int MAX_PER_CHANGE = 500;

    public function __construct(
        private Authorizer $authorizer,
        private CategoryRepository $categories,
        private ListLocks $locks,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws CategoryNotFound|InvalidCatalogAttribute|Unauthorized
     */
    public function handle(RankCategories $command): void
    {
        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized(self::PERMISSION);
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));

        // Only now, and only for someone who may change it, is the store looked up. A store that is
        // off is prepared before it opens, its menu order too (owner, 2026-10-04, amendment 4(f)); a
        // store opened later starts with no order, its admins set it (amendment 2(b)).
        if ($this->platform->store($store) === null) {
            throw new InvalidCatalogAttribute('store', 'a store');
        }

        if (count($command->ranks) > self::MAX_PER_CHANGE) {
            throw new InvalidCatalogAttribute('rank', 'at most '.self::MAX_PER_CHANGE.' categories at once');
        }

        $ranks = [];

        // The map arrives from a request: a place that is not a whole number is refused, not a crash.
        foreach ($command->ranks as $categoryId => $rank) {
            if (! is_int($rank)) {
                throw new InvalidCatalogAttribute('rank', 'a whole number');
            }

            $ranks[strtolower((string) $categoryId)] = ListPosition::check($rank, 'rank');
        }

        $this->db->transaction(function () use ($store, $ranks): void {
            $this->locks->lock(ListLocks::CATEGORIES);

            foreach ($ranks as $categoryId => $rank) {
                $category = $this->categories->find($categoryId) ?? throw new CategoryNotFound($categoryId);
                $was = $this->categories->rankIn($store->value, $category->id());

                if ($was === $rank) {
                    continue;
                }

                $this->categories->placeIn($category->id(), [$store->value], $rank);
                $entry = ListAudit::changed('category', 'ranked', $category->id(), ['rank' => $was], ['rank' => $rank], $store->value);

                if ($entry !== null) {
                    $this->platform->recordAudit($entry);
                }
            }
        }, 3);
    }
}
