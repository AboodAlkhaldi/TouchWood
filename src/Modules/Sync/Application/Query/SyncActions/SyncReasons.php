<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\SyncActions;

/**
 * **Why an entry's item is drawn disabled** (sync.md §4.1 Y1's ⋯ menu), in the page's language, written
 * under the item: Retry Read on a size deleted since, and Dismiss Entry on a failed read's entry.
 */
final readonly class SyncReasons
{
    public function __construct(
        public string $sizeGone,
        public string $readFailedStays,
    ) {}
}
