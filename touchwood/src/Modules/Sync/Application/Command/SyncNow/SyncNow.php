<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\SyncNow;

/**
 * Read the store now (sync.md §1.3): staff know something changed, or a key was replaced.
 */
final readonly class SyncNow
{
    public function __construct(
        public string $storeId,
    ) {}
}
