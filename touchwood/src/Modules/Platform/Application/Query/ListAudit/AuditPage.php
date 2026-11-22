<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAudit;

/**
 * One page of the audit log, newest first (frontend.md 3.5, E6).
 */
final readonly class AuditPage
{
    /**
     * @param  list<AuditEntryRow>  $entries
     */
    public function __construct(
        public array $entries,
        /** The cursor for the next page, or null when this is the end of the log. */
        public ?string $nextOccurredAt,
        public ?int $nextId,
    ) {}
}
