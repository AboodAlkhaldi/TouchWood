<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\MarkInboxEntryRead;

/** Opening an entry of my bell marks it read (ops.md §1.2). */
final readonly class MarkInboxEntryRead
{
    public function __construct(
        public string $entryId,
    ) {}
}
