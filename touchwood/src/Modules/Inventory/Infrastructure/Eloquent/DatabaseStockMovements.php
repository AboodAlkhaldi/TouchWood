<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\ValueObject\LedgerLine;
use Modules\Inventory\Domain\ValueObject\LedgerNote;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Shared\Application\ActorContext;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * `inventory.stock_movements` (inventory.md §1.6, §5): one row a change of in stock, by whoever acts
 * now - recorded as the audit log records its actor, with whoever queued the job when the system acts
 * in one. Its time is PostgreSQL's clock.
 */
final readonly class DatabaseStockMovements implements StockMovements
{
    public function __construct(
        private ConnectionInterface $db,
        private ActorContext $actors,
    ) {}

    public function record(StoreId $store, string $variantId, MovementReason $reason, int $before, int $after, string|LedgerNote|null $note, string $reference): void
    {
        $coded = $note instanceof LedgerNote ? $note : null;

        $actor = $this->actors->current();

        $this->db->table('inventory.stock_movements')->insert([
            'store_id' => $store->value,
            'variant_id' => $variantId,
            'reason' => $reason->value,
            'change' => $after - $before,
            'before' => $before,
            'after' => $after,
            'note' => $coded === null ? $note : null,
            'note_code' => $coded?->code,
            'note_values' => $coded === null || $coded->values === [] ? null : json_encode($coded->values, JSON_THROW_ON_ERROR),
            'reference' => $reference,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'requested_by_type' => $actor->requestedBy?->type->value,
            'requested_by_id' => $actor->requestedBy?->id,
        ]);
    }

    public function referenced(string $reference): bool
    {
        return $this->db->table('inventory.stock_movements')->where('reference', $reference)->exists();
    }

    public function history(StoreId $store, string $variantId, ?int $beforeId, int $limit): array
    {
        $query = $this->db->table('inventory.stock_movements as m')
            ->where('m.store_id', $store->value)
            ->where('m.variant_id', strtolower(trim($variantId)))
            ->select(['m.id', 'm.created_at', 'm.reason', 'm.before', 'm.after', 'm.note', 'm.note_code', 'm.note_values', 'm.actor_type', 'm.actor_id', 'm.requested_by_type', 'm.requested_by_id'])
            // The order a shipment or a return was for: a shipment's reference names it ("ship:{order}:…");
            // a return's names the return, whose order its lines keep.
            ->selectRaw(<<<'SQL'
                CASE m.reason
                    WHEN 'SHIPPED' THEN split_part(m.reference, ':', 2)
                    WHEN 'RETURNED' THEN (
                        SELECT r.order_id FROM inventory.return_lines r
                        WHERE r.return_id = split_part(m.reference, ':', 2) AND r.variant_id = m.variant_id
                        LIMIT 1
                    )
                END AS order_id
                SQL)
            // Newest first: the id rises with every line written, and breaks a tie of one transaction's moment.
            ->orderByDesc('m.id')
            ->limit(max(1, $limit));

        if ($beforeId !== null) {
            $query->where('m.id', '<', $beforeId);
        }

        return array_values(array_map(static fn (stdClass $row): LedgerLine => new LedgerLine(
            (int) $row->id,
            CarbonImmutable::parse((string) $row->created_at)->toDateTimeImmutable(),
            MovementReason::from((string) $row->reason),
            (int) $row->before,
            (int) $row->after,
            $row->note === null ? null : (string) $row->note,
            (string) $row->actor_type,
            $row->actor_id === null ? null : (string) $row->actor_id,
            $row->requested_by_type === null ? null : (string) $row->requested_by_type,
            $row->requested_by_id === null ? null : (string) $row->requested_by_id,
            $row->order_id === null || $row->order_id === '' ? null : (string) $row->order_id,
            $row->note_code === null ? null : LedgerNote::stored((string) $row->note_code, is_string($row->note_values) ? (array) json_decode($row->note_values, true) : []),
        ), $query->get()->all()));
    }

    public function lastProviderChanges(array $stores, array $variantIds): array
    {
        $storeIds = IdArray::valid(array_map(static fn (StoreId $store): string => $store->value, $stores));
        $ids = IdArray::valid($variantIds);

        if ($storeIds === [] || $ids === []) {
            return [];
        }

        $found = [];

        // Each size's last PROVIDER line in each store, by the ledger's index (store, size, id).
        foreach ($this->db->select(<<<'SQL'
            SELECT DISTINCT ON (store_id, variant_id) store_id, variant_id, created_at
            FROM inventory.stock_movements
            WHERE store_id = ANY(?::text[]) AND variant_id = ANY(?::text[]) AND reason = 'PROVIDER'
            ORDER BY store_id, variant_id, id DESC
            SQL, [IdArray::of($storeIds), IdArray::of($ids)]) as $row) {
            $found[(string) $row->store_id][(string) $row->variant_id] = CarbonImmutable::parse((string) $row->created_at)->toDateTimeImmutable();
        }

        return $found;
    }
}
