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
        public string $subjectId,
        public string $source,
        public string $actorType,
        public ?string $actorId,
        /** Their name, when the actor is somebody this reader may be told about. */
        public ?string $actorName,
        public ?string $requestedByType,
        public ?string $requestedById,
        /** The store it belongs to, by name; null for a change that belongs to no store. */
        public ?string $storeName,
        /** Only ever a staff member's. */
        public ?string $ipAddress,
        public array $changes,
    ) {}
}
