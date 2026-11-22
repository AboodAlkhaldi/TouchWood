<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry of the audit log (frontend.md 3.5, E6).
 *
 * A personal field shows only as "changed" - not because this hides it, but because the value was
 * never recorded: the log is kept forever, and anonymizing an account must never have to rewrite
 * history (platform.md 1.5).
 *
 * An entry about a private file, read by someone who may not see private files, comes **withheld**:
 * no subject id and no changes (b2b.md amendment 8(c)).
 */
#[TypeScript]
final class AuditRow extends Data
{
    /**
     * @param  list<AuditChangeRow>  $changes
     */
    public function __construct(
        public string $id,
        public string $occurredAt,
        public string $action,
        /** The action in words, where the module wrote one down; the action itself otherwise. */
        public string $actionLabel,
        public string $subjectType,
        /** Null when withheld. */
        public ?string $subjectId,
        public string $source,
        public string $actorType,
        /**
         * Null for a Super Admin read by anyone but another Super Admin: the name then says "System
         * administrator", and the screen shows no id and no link (access.md amendment 54).
         */
        public ?string $actorId,
        /** A staff actor's name, as this reader may be shown it (amendment 54); null for anybody else. */
        public ?string $actorName,
        public ?string $requestedByType,
        public ?string $requestedById,
        /** The store it belongs to, by name; null for a change that belongs to no store. */
        public ?string $storeName,
        /** Only ever a staff member's. */
        public ?string $ipAddress,
        public array $changes,
        /** A private file's entry, for a reader who may not see private files. */
        public bool $withheld,
        /** Whoever queued the job, when a staff member did, named as for the actor (amendment 54). */
        public ?string $requestedByName = null,
        /**
         * When the entry is about a staff member, their name as this reader may be shown it — "System
         * administrator" for a Super Admin, whose id and changes are then left out (amendment 54).
         */
        public ?string $subjectName = null,
    ) {}
}
