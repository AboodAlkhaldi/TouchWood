<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\SyncStatus;

/**
 * A store's status line on the Sync Report (sync.md §4.1.3 `SyncStatus`, S8; §4.1 Y1).
 */
final readonly class SyncStatus
{
    public function __construct(
        public string $storeId,
    ) {}
}
