<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAudit;

/**
 * The read side of the audit log (frontend.md §3.5, E6).
 *
 * A read model, not a repository: the log is append-only and has no domain model to load. Nothing
 * here ever writes — the table's own trigger refuses an UPDATE or a DELETE whoever asks
 * (platform.md §1.5, §5.5).
 */
interface AuditReader
{
    /**
     * One page of entries, newest first.
     *
     * @param  list<string>|null  $storeIds  the stores to read; null for every store, which also
     *                                       means the entries that belong to no store at all
     * @return list<array<string, mixed>>
     */
    public function page(?array $storeIds, ListAudit $query): array;

    /**
     * The actions that actually appear in the log, for the filter to offer.
     *
     * Read from the log rather than from a list written out somewhere: a module that starts
     * recording a new action appears in the filter without anybody remembering to add it.
     *
     * @param  list<string>|null  $storeIds  as above
     * @return list<string> ordered by name
     */
    public function actions(?array $storeIds): array;
}
