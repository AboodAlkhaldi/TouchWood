<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * Whether a store is wired to a provider (pricing.md §1.1 rule 9): its retail prices then come from the
 * provider and are read-only in the panel. Sync answers it once it is built, after the provider's
 * answers; until then no store is wired.
 */
interface StoreWiring
{
    public function isWired(StoreId $store): bool;
}
