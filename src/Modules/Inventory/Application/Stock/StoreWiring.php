<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Shared\Domain\ValueObject\StoreId;

/**
 * Whether a store is wired to a provider (inventory.md §1.1 rule 3): its stock is then the provider's,
 * read-only here, and only there may a size be stock-dependent. Sync answers it once it is built, after
 * the provider's answers; until then no store is wired.
 */
interface StoreWiring
{
    public function isWired(StoreId $store): bool;
}
