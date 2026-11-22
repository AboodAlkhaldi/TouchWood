<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\External;

use Modules\Inventory\Public\Contracts\StoreWiring;
use Shared\Domain\ValueObject\StoreId;

/**
 * No store is wired to a provider until Sync is built, after the provider's team answers (inventory.md
 * §1.1 rule 3; handoff §12.2): every store counts its own stock until then.
 */
final readonly class NoStoreWired implements StoreWiring
{
    public function isWired(StoreId $store): bool
    {
        return false;
    }

    public function wiredStores(): array
    {
        return [];
    }
}
