<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAudit;

use Modules\Platform\Application\Audit\MediaAudit;
use Modules\Platform\Application\Media\PrivateMedia;
use Modules\Platform\Public\Contracts\StaffNames;
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
 *
 * **Except which private file an entry is about** (b2b.md amendment 8(c)). A company's papers are
 * private media, whose entries belong to no store; a reader of every store who may not see private
 * files still gets each entry — what was done, when, by whom — but not the file's id nor what
 * changed, and asks an admin who may. The entry stays on the page, so paging is unchanged.
 */
final readonly class ListAuditHandler
{
    public const string PERMISSION = PlatformPermissions::AUDIT_VIEW;

    /** Enough to fill a screen without asking the log for more than anybody reads. */
    public const int PER_PAGE = 50;

    public function __construct(
        private Authorizer $authorizer,
        private AuditReader $reader,
        private PrivateMedia $private,
        private StaffNames $staffNames,
    ) {}

    /**
     * @throws Unauthorized when they may read no store's log
     */
    public function handle(ListAudit $query): AuditPage
    {
        $stores = $this->storeIds();
        $perPage = min(max($query->perPage, 1), self::PER_PAGE);

        // Filtering by a Super Admin's id, for a reader who may not know they exist, is answered as
        // for an id that never existed: no entries (access.md amendment 54).
        if ($query->actorId !== null && ($this->staffNames->forReader([$query->actorId])[strtolower($query->actorId)] ?? null)?->hidden === true) {
            return new AuditPage([], null, null);
        }

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
        $entries = $this->named($this->withholdPrivateFiles(array_map($this->row(...), $rows)));
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
     * @param  list<AuditEntryRow>  $entries
     * @return list<AuditEntryRow>
     */
    private function withholdPrivateFiles(array $entries): array
    {
        $media = [];

        foreach ($entries as $entry) {
            if ($entry->subjectType === MediaAudit::SUBJECT && $entry->subjectId !== null) {
                $media[] = $entry->subjectId;
            }
        }

        // Nothing to ask about, or somebody who may see them all.
        if ($media === [] || $this->private->seen()) {
            return $entries;
        }

        $private = array_flip($this->reader->privateMedia($media));

        return array_map(
            static fn (AuditEntryRow $entry): AuditEntryRow => $entry->subjectType === MediaAudit::SUBJECT && isset($private[(string) $entry->subjectId])
                ? $entry->withSubjectWithheld()
                : $entry,
            $entries,
        );
    }

    /**
     * Each staff member on the page named as this reader may be shown them — the actor, whoever
     * queued a job, an entry's subject — asked once for the whole page (access.md amendment 54). A
     * Super Admin, to anyone but another, is "System administrator", with no id or address.
     *
     * @param  list<AuditEntryRow>  $entries
     * @return list<AuditEntryRow>
     */
    private function named(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            foreach ([$entry->actorId, $entry->requestedById, $entry->subjectId] as $id) {
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        $names = $ids === [] ? [] : $this->staffNames->forReader($ids);

        return $names === [] ? $entries : array_map(static fn (AuditEntryRow $entry): AuditEntryRow => $entry->named($names), $entries);
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
