<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAudit;

use Modules\Platform\Public\Dto\StaffNameDto;

/**
 * One entry of the audit log (frontend.md 3.5, E6).
 *
 * Personal fields are not hidden here: they were never written. A module records a person's name,
 * email, phone or address with personal(), which keeps only that it changed, because the log is
 * kept forever and anonymizing an account must never have to rewrite history (platform.md 1.5).
 *
 * An entry about a **private file** reaches a reader who may not see private files **withheld**:
 * what was done, when, by whom and from where, but no subject id and no changes (b2b.md amendment
 * 8(c)).
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
        /** Null when withheld. */
        public ?string $subjectId,
        public array $changes,
        /** Only ever a staff member's: the table refuses one for anybody else. */
        public ?string $ipAddress,
        /** A private file's entry, read by someone who may not see private files. */
        public bool $withheld = false,
        /** The staff actor's name, as this reader may be shown it (access.md amendment 54). */
        public ?string $actorName = null,
        /** Whoever queued the job, when a staff member did, named as this reader may be shown them. */
        public ?string $requestedByName = null,
        /** When the entry is about a staff member: their name, as this reader may be shown it. */
        public ?string $subjectName = null,
    ) {}

    /**
     * The same entry, without which file it is about or what changed.
     */
    public function withSubjectWithheld(): self
    {
        return new self(
            $this->id,
            $this->occurredAt,
            $this->source,
            $this->storeId,
            $this->actorType,
            $this->actorId,
            $this->requestedByType,
            $this->requestedById,
            $this->action,
            $this->subjectType,
            null,
            [],
            $this->ipAddress,
            true,
            $this->actorName,
            $this->requestedByName,
            $this->subjectName,
        );
    }

    /**
     * The same entry with its staff named for this reader (access.md §1.6, amendment 54). A Super
     * Admin read by anyone but another Super Admin is "System administrator": the id, the address
     * and — for an entry about them — what changed are left out; the entry itself stays.
     *
     * @param  array<string, StaffNameDto>  $names  keyed by lower-cased id
     */
    public function named(array $names): self
    {
        $actor = $this->actorType === 'STAFF' && $this->actorId !== null ? ($names[strtolower($this->actorId)] ?? null) : null;
        $requester = $this->requestedByType === 'STAFF' && $this->requestedById !== null ? ($names[strtolower($this->requestedById)] ?? null) : null;
        $subject = $this->subjectId !== null ? ($names[strtolower($this->subjectId)] ?? null) : null;

        return new self(
            $this->id,
            $this->occurredAt,
            $this->source,
            $this->storeId,
            $this->actorType,
            $actor?->hidden === true ? null : $this->actorId,
            $this->requestedByType,
            $requester?->hidden === true ? null : $this->requestedById,
            $this->action,
            $this->subjectType,
            $subject?->hidden === true ? null : $this->subjectId,
            $subject?->hidden === true ? [] : $this->changes,
            $actor?->hidden === true ? null : $this->ipAddress,
            $this->withheld,
            $actor->name ?? $this->actorName,
            $requester->name ?? $this->requestedByName,
            $subject->name ?? $this->subjectName,
        );
    }
}
