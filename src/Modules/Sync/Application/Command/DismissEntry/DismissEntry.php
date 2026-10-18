<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Command\DismissEntry;

/**
 * Staff close a report entry: seen, or Leave it (sync.md §1.6).
 */
final readonly class DismissEntry
{
    public function __construct(
        public int $entryId,
    ) {}
}
