<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAudit;

/**
 * One entry of the audit log (frontend.md 3.5, E6).
 *
 * Personal fields are not hidden here: they were never written. A module records a person's name,
 * email, phone or address with personal(), which keeps only that it changed, because the log is
 * kept forever and anonymizing an account must never have to rewrite history (platform.md 1.5).
 */
final readonly class AuditEntryRow
{
    /**
     * @param  array<string, mixed>  $changes  attribute => [from, to], or the word "changed"
     */
    public function __construct(
        public string $id,
        public string $occurredAt,
        public string $source,
        public ?string $storeId,
        public string $actorType,
        public ?string $actorId,
        /** Who asked for it, when the system did it inside a queued job. */
        public ?string $requestedByType,
        public ?string $requestedById,
        public string $action,
        public string $subjectType,
        public string $subjectId,
        public array $changes,
        /** Only ever a staff member's: the table refuses one for anybody else. */
        public ?string $ipAddress,
    ) {}
}
