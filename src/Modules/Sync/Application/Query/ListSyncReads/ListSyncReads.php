<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ListSyncReads;

/**
 * The page of a store's reads (sync.md §9.3 #19), a page at a time.
 */
final readonly class ListSyncReads
{
    public function __construct(
        public string $storeId,
        public int $page = 1,
    ) {}
}
