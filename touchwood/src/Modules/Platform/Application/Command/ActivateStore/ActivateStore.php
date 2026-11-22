<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\ActivateStore;

/**
 * Turn a store on (platform.md §3; owner, 2026-10-01). Super Admin only.
 */
final readonly class ActivateStore
{
    public function __construct(
        public string $storeCode,
    ) {}
}
