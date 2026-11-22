<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\External;

use Modules\Pricing\Public\Contracts\StoreWiring;
use Shared\Domain\ValueObject\StoreId;

/**
 * No store is wired to a provider until Sync is built, after the provider's team answers (pricing.md
 * §1.1 rule 9; handoff §12.2): every store's retail prices are set here until then.
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
