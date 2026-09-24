<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use JsonException;
use Modules\Platform\Application\Query\ListAudit\AuditReader;
use Modules\Platform\Application\Query\ListAudit\ListAudit;
use stdClass;

/**
 * Reads the audit log, newest first, over the indexes the table was built with (platform.md §5.4).
 *
 * The order is `occurred_at DESC, id DESC` throughout, which is `audit_entries_store_idx` with the
 * identity column breaking a tie — two entries can share a moment, and a page boundary that falls
 * inside one of those moments must not show a row twice or skip one.
 */
final readonly class DatabaseAuditReader implements AuditReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    /**
     * @param  list<string>|null  $storeIds
     * @return list<array<string, mixed>>
     */
    public function page(?array $storeIds, ListAudit $query): array
    {
        $rows = $this->filtered($storeIds, $query)
            ->select([
                'id', 'occurred_at', 'source', 'store_id', 'actor_type', 'actor_id',
                'requested_by_type', 'requested_by_id', 'action', 'subject_type', 'subject_id',
                'changes', 'ip_address',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($query->perPage)
            ->get();

        return array_values(array_map($this->toRow(...), $rows->all()));
    }

    /**
     * @param  list<string>|null  $storeIds
     * @return list<string>
     */
    public function actions(?array $storeIds): array
    {
        $rows = $this->reachable($storeIds)
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return array_values(array_map(static fn (mixed $action): string => (string) $action, $rows->all()));
    }

    /**
     * @param  list<string>|null  $storeIds
     */
    private function filtered(?array $storeIds, ListAudit $query): Builder
    {
        $rows = $this->reachable($storeIds);

        if ($query->from !== null) {
            $rows->where('occurred_at', '>=', $query->from);
        }

        if ($query->until !== null) {
            // The whole of that day, not the moment it begins: somebody filtering "until the 3rd"
            // means everything that happened on the 3rd.
            $rows->where('occurred_at', '<', $query->until.' 23:59:59.999999');
        }

        if ($query->actorId !== null) {
            $rows->where('actor_id', strtolower($query->actorId));
        }

        if ($query->action !== null) {
            $rows->where('action', $query->action);
        }

        if ($query->source !== null) {
            $rows->where('source', $query->source);
        }

        if ($query->cursorOccurredAt !== null && $query->cursorId !== null) {
            // Everything strictly older than the last row shown, by the same order the index keeps.
            $rows->whereRaw('(occurred_at, id) < (?, ?)', [$query->cursorOccurredAt, $query->cursorId]);
        }

        return $rows;
    }

    /**
     * The entries this reader may see at all.
     *
     * @param  list<string>|null  $storeIds
     */
    private function reachable(?array $storeIds): Builder
    {
        $rows = $this->db->table('platform.audit_entries');

        if ($storeIds === null) {
            return $rows;
        }

        // Their stores, and nothing global: an entry that belongs to no store is the system's
        // rather than a shop's, and needs every store to read.
        return $storeIds === [] ? $rows->whereRaw('false') : $rows->whereIn('store_id', $storeIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'occurred_at' => $row->occurred_at,
            'source' => $row->source,
            'store_id' => $row->store_id,
            'actor_type' => $row->actor_type,
            'actor_id' => $row->actor_id,
            'requested_by_type' => $row->requested_by_type,
            'requested_by_id' => $row->requested_by_id,
            'action' => $row->action,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'changes' => $this->changes($row->changes),
            'ip_address' => $row->ip_address,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function changes(mixed $json): array
    {
        if (! is_string($json)) {
            return [];
        }

        try {
            $changes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Kept forever means kept through every future change of shape: one unreadable row
            // must not take the whole screen down with it.
            return [];
        }

        return is_array($changes) ? $changes : [];
    }
}
