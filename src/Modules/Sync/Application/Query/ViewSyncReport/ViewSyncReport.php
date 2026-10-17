<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncReport;

/**
 * A store's Sync report (sync.md §1.6): its open entries, a page at a time, of one kind or all.
 */
final readonly class ViewSyncReport
{
    public function __construct(
        public string $storeId,
        public ?string $kind = null,
        public int $page = 1,
    ) {}
}
