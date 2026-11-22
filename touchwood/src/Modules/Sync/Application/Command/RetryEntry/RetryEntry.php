<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\RetryEntry;

/**
 * Read an entry's code again (sync.md §1.6: Retry).
 */
final readonly class RetryEntry
{
    public function __construct(
        public int $entryId,
    ) {}
}
