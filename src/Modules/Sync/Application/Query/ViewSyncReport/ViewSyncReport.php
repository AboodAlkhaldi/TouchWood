<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncReport;

/**
 * A store's Sync report (sync.md §1.6, §4.1 Y1 Problems): its open entries, 50 at a time after the last
 * one shown (`after`, Show More), of one kind or all.
 */
final readonly class ViewSyncReport
{
    public function __construct(
        public string $storeId,
        public ?string $kind = null,
        public ?string $after = null,
    ) {}
}
