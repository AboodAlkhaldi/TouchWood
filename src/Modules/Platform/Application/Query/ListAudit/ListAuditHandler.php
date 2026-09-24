<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAudit;

use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * The audit log, for the stores this person may read it in (frontend.md §3.5, E6).
 *
 * **An entry that belongs to no store needs every store.** A currency, a setting that is global, a
 * staff member's role: those changes are the system's rather than a shop's, and somebody who reads
 * one store's log has no more claim on them than on another shop's. So a reader with some stores
 * sees their stores' entries and nothing else; only a reader of every store sees the rest.
 *
 * Personal fields are not filtered out here, because they were never recorded: a module writes them
 * with personal(), which keeps only that they changed (platform.md §1.5). What this reads is
 * already safe to show.
 */
final readonly class ListAuditHandler
{
    public const string PERMISSION = PlatformPermissions::AUDIT_VIEW;

    /** Enough to fill a screen without asking the log for more than anybody reads. */
    public const int PER_PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private AuditReader $reader,
    ) {}

    /**
     * @throws Unauthorized when they may read no store's log
     */
    public function handle(ListAudit $query): AuditPage
    {
        $stores = $this->storeIds();
        $perPage = min(max($query->perPage, 1), self::PER_PAGE);

        // One more than a page, to learn whether there is another page without counting the log.
        $rows = $this->reader->page($stores, new ListAudit(
            $query->from,
            $query->until,
            $query->actorId,
            $query->action,
            $query->source,
            $query->cursorOccurredAt,
            $query->cursorId,
            $perPage + 1,
        ));

        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);
        $entries = array_map($this->row(...), $rows);
        $last = $more ? end($entries) : null;

        return new AuditPage(
            $entries,
            $last === false || $last === null ? null : $last->occurredAt,
            $last === false || $last === null ? null : (int) $last->id,
        );
    }

    /**
     * The actions the filter offers, within the same reach.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->reader->actions($this->storeIds());
    }

    /**
     * @return list<string>|null null for every store
     */
    private function storeIds(): ?array
    {
        $reach = $this->authorizer->storesWith(self::PERMISSION);

        if ($reach === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        return $reach === null ? null : array_map(static fn ($store): string => $store->value, $reach);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function row(array $row): AuditEntryRow
    {
        $changes = $row['changes'];

        return new AuditEntryRow(
            (string) $row['id'],
            (string) $row['occurred_at'],
            (string) $row['source'],
            $row['store_id'] === null ? null : (string) $row['store_id'],
            (string) $row['actor_type'],
            $row['actor_id'] === null ? null : (string) $row['actor_id'],
            $row['requested_by_type'] === null ? null : (string) $row['requested_by_type'],
            $row['requested_by_id'] === null ? null : (string) $row['requested_by_id'],
            (string) $row['action'],
            (string) $row['subject_type'],
            (string) $row['subject_id'],
            is_array($changes) ? $changes : [],
            $row['ip_address'] === null ? null : (string) $row['ip_address'],
        );
    }
}
