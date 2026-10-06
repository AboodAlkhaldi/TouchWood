<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * Where bringing an import's products in waits for a worker (catalog.md §1.12, page part 3): a zip of
 * photos takes minutes, so it runs from the queue.
 */
interface ImportQueue
{
    /** Queued once the current transaction commits, so it never runs for a change rolled back. */
    public function bringIn(string $importId): void;
}
