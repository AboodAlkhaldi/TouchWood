<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\RunRead;

/**
 * Run one queued read of a store (sync.md §1.3) - the system's.
 */
final readonly class RunRead
{
    public function __construct(
        public string $readId,
    ) {}
}
