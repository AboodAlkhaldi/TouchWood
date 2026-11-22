<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\CountSyncReport;

/**
 * How many entries a store's Sync report has open - the count beside its menu item (sync.md §1.6).
 */
final readonly class CountSyncReport
{
    public function __construct(
        public string $storeId,
    ) {}
}
