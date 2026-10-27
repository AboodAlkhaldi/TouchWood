<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Query\Staff\StaffReturnReads;
use stdClass;

/** `StaffReturnReads` in SQL (sales.md §3, §5). */
final readonly class DatabaseStaffReturnReads implements StaffReturnReads
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function returns(?array $storeIds, ?array $statuses, ?string $afterReturnId, int $limit): array
    {
        $query = $this->db->table('sales.returns AS r')->join('sales.orders AS o', 'o.id', '=', 'r.order_id')
            ->orderByDesc('r.requested_at')->orderByDesc('r.id')->limit($limit)
            ->select(['r.id', 'r.order_id', 'o.number', 'r.store_id', 'o.customer_id', 'r.status', 'r.requested_at', 'r.refund_due_minor', 'o.currency_code']);

        if ($storeIds !== null) {
            $query->whereIn('r.store_id', $storeIds);
        }

        if ($statuses !== null) {
            $query->whereIn('r.status', $statuses);
        }

        if ($afterReturnId !== null) {
            $after = $this->db->table('sales.returns')->where('id', $afterReturnId)->first(['requested_at', 'id']);

            if (! $after instanceof stdClass) {
                return [];
            }

            $query->whereRaw('(r.requested_at, r.id) < (?, ?)', [(string) $after->requested_at, (string) $after->id]);
        }

        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $query->get()->all()));
    }

    public function returnOf(string $returnId): ?array
    {
        $row = $this->db->table('sales.returns AS r')->join('sales.orders AS o', 'o.id', '=', 'r.order_id')
            ->where('r.id', $returnId)->first(['r.*', 'o.number', 'o.customer_id', 'o.currency_code']);

        return $row instanceof stdClass ? (array) $row : null;
    }

    public function lines(string $returnId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $this->db->table('sales.return_lines AS rl')
            ->join('sales.order_lines AS l', 'l.id', '=', 'rl.order_line_id')->where('rl.return_id', $returnId)->orderBy('l.is_gift')->orderBy('l.id')
            ->get(['rl.order_line_id', 'l.variant_id', 'l.name_ar', 'l.name_en', 'l.variant_values', 'l.code', 'l.is_gift', 'rl.quantity', 'rl.received_quantity', 'rl.restocked_quantity'])->all()));
    }

    public function photos(string $returnId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $this->db->table('sales.return_photos')
            ->where('return_id', $returnId)->orderBy('position')->get(['media_id', 'position'])->all()));
    }

    public function storeOfFile(string $mediaId): ?string
    {
        $store = $this->db->table('sales.transfer_documents')->where('media_id', $mediaId)->value('store_id')
            ?? $this->db->table('sales.return_photos AS p')->join('sales.returns AS r', 'r.id', '=', 'p.return_id')->where('p.media_id', $mediaId)->value('r.store_id');

        return $store === null ? null : (string) $store;
    }
}
