<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeactivateStore;

/**
 * Turn a store off (platform.md §3; owner, 2026-10-01). Super Admin only; never the base store.
 */
final readonly class DeactivateStore
{
    public function __construct(
        public string $storeCode,
    ) {}
}
