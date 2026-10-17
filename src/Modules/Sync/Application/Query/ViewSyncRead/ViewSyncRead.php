<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

/**
 * One read's page (sync.md §9.3 #19): what it changed and what it reported, each section a page at a
 * time.
 */
final readonly class ViewSyncRead
{
    public function __construct(
        public string $readId,
        public int $page = 1,
    ) {}
}
