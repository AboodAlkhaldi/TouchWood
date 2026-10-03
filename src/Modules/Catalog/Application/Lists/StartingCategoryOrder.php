<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * A store opened after categories exist starts with the base store's order of its menu, as a
 * category added later starts with the same place everywhere (catalog.md §1.5, amendment 1(d)); its
 * admins change it there afterwards. It **only ever adds**: a category the store already has a place
 * for keeps it, so it may run again safely. Under the categories' lock, so a category added at the
 * same moment is either copied or placed by its own adding — never missed.
 */
final readonly class StartingCategoryOrder
{
    public function __construct(
        private CategoryRepository $categories,
        private ListLocks $locks,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    public function forStore(string $storeId): void
    {
        $base = array_values(array_filter($this->platform->allStores(), static fn (StoreDto $store): bool => $store->isBase))[0] ?? null;

        if ($base === null || $base->id === strtolower($storeId)) {
            return;
        }

        $this->db->transaction(function () use ($base, $storeId): void {
            $this->locks->lock(ListLocks::CATEGORIES);
            $this->categories->copyRanks($base->id, $storeId);
        }, 3);
    }
}
