<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Dto;

/**
 * One audited change, recorded by the module that made it. The actor, time, correlation id
 * and staff IP address are filled in by Platform from the current context.
 */
final readonly class AuditEntryDto
{
    /**
     * @param  string  $action  "{module}.{resource}.{past-tense}", e.g. "platform.store.updated"
     * @param  string  $subjectType  "{module}.{resource}", e.g. "platform.store"
     * @param  string|null  $storeId  the store the change belongs to; null for global resources
     */
    public function __construct(
        public string $action,
        public string $subjectType,
        public string $subjectId,
        public ?string $storeId,
        public AuditChanges $changes,
    ) {}
}
