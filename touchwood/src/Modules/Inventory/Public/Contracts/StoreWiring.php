<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * Whether a store is wired to a provider (inventory.md §1.1 rule 3): its stock is then the provider's,
 * read-only here, and only there may a size be stock-dependent. Sync answers it once it is built, after
 * the provider's answers; until then no store is wired.
 */
interface StoreWiring
{
    public function isWired(StoreId $store): bool;

    /**
     * Every store wired now, by id, in one read - for a screen asking of many stores at once (stage 5's
     * screens step 4: a product's stock in every store, what the screens may offer), as Pricing's
     * `StoreWiring::wiredStores` answers.
     *
     * @return list<string>
     */
    public function wiredStores(): array;
}
