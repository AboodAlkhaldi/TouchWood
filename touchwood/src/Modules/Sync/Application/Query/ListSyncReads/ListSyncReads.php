<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ListSyncReads;

/**
 * The page of a store's reads (sync.md §4.1 Y1 Reads, §9.3 #19), 50 at a time after the last one shown
 * (`after`, Show More).
 */
final readonly class ListSyncReads
{
    public function __construct(
        public string $storeId,
        public ?string $after = null,
    ) {}
}
