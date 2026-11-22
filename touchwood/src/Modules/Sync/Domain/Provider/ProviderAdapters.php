<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Provider;

use Modules\Sync\Domain\ValueObject\ProviderKind;

/**
 * The adapter for each provider (sync.md §2.1).
 */
interface ProviderAdapters
{
    public function for(ProviderKind $provider): ProviderAdapter;
}
