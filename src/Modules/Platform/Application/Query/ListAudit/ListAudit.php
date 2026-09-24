<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAudit;

/**
 * The audit log's read (frontend.md 3.5, E6).
 *
 * Paged by keyset rather than by page number: the log only grows, and an offset would walk rows it
 * has already shown every time somebody turns a page (platform.md 5.4). The cursor is the last row
 * seen - its time and its id, because two entries can share a moment.
 */
final readonly class ListAudit
{
    public function __construct(
        /** ISO dates, inclusive; the day itself, not a moment in it. */
        public ?string $from = null,
        public ?string $until = null,
        public ?string $actorId = null,
        public ?string $action = null,
        public ?string $source = null,
        public ?string $cursorOccurredAt = null,
        public ?int $cursorId = null,
        public int $perPage = 50,
    ) {}
}
