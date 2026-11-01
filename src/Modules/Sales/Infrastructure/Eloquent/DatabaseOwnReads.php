<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Query\Own\OwnReads;
use stdClass;

/** `OwnReads` in SQL (sales.md §3, §5): the customer's own rows only. */
final readonly class DatabaseOwnReads implements OwnReads
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function orders(string $customerId, ?string $afterNumber, int $limit): array
    {
        $query = $this->db->table('sales.orders AS o')->where('o.customer_id', $customerId)
            ->orderByDesc('o.placed_at')->orderByDesc('o.id')->limit($limit)
            ->select(['o.number', 'o.store_id', 'o.placed_at', 'o.status', 'o.shipment_status', 'o.currency_code', 'o.order_total_minor', 'o.due_minor', 'o.paid_minor', 'o.refunded_minor', 'o.shipping_fee_pending'])
            ->selectRaw('(SELECT coalesce(sum(l.quantity), 0) FROM sales.order_lines l WHERE l.order_id = o.id AND NOT l.is_gift) AS pieces');

        if ($afterNumber !== null) {
            $after = $this->db->table('sales.orders')->where('customer_id', $customerId)->where('number', $afterNumber)->first(['placed_at', 'id']);

            if (! $after instanceof stdClass) {
                return [];
            }

            $query->whereRaw('(o.placed_at, o.id) < (?, ?)', [(string) $after->placed_at, (string) $after->id]);
        }

        return array_values(array_map(static fn (stdClass $row): array => [
            'number' => (string) $row->number, 'storeId' => (string) $row->store_id, 'placedAt' => CarbonImmutable::parse((string) $row->placed_at),
            'status' => (string) $row->status, 'shipment' => (string) $row->shipment_status, 'currency' => (string) $row->currency_code,
            'total' => (int) $row->order_total_minor, 'due' => (int) $row->due_minor, 'paid' => (int) $row->paid_minor, 'refunded' => (int) $row->refunded_minor,
            'pieces' => (int) $row->pieces, 'shippingPending' => (bool) $row->shipping_fee_pending,
        ], $query->get()->all()));
    }

    public function order(string $customerId, string $number): ?array
    {
        $row = $this->db->table('sales.orders')->where('customer_id', $customerId)->where('number', $number)->first();

        return $row instanceof stdClass ? (array) $row : null;
    }

    public function lines(string $orderId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $this->db->table('sales.order_lines AS l')
            ->leftJoin('sales.order_edits AS e', 'e.id', '=', 'l.edit_id')->where('l.order_id', $orderId)
            ->orderBy('l.is_gift')->orderByRaw('l.edit_id IS NOT NULL, e.at, l.edit_id, l.id')->get(['l.*'])->all()));
    }

    public function returns(string $orderId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $this->db->table('sales.returns')
            ->where('order_id', $orderId)->orderByDesc('requested_at')->orderByDesc('id')->get(['id', 'status', 'requested_at', 'refund_due_minor', 'staff_reason'])->all()));
    }

    public function transferDocuments(string $orderId): int
    {
        return $this->db->table('sales.transfer_documents')->where('order_id', $orderId)->count();
    }

    public function returnOf(string $customerId, string $returnId): ?array
    {
        $row = $this->db->table('sales.returns AS r')->join('sales.orders AS o', 'o.id', '=', 'r.order_id')
            ->where('r.id', $returnId)->where('o.customer_id', $customerId)->first(['r.*', 'o.number', 'o.currency_code']);

        return $row instanceof stdClass ? (array) $row : null;
    }

    public function returnLines(string $returnId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $this->db->table('sales.return_lines AS rl')
            ->join('sales.order_lines AS l', 'l.id', '=', 'rl.order_line_id')->where('rl.return_id', $returnId)->orderBy('l.is_gift')->orderBy('l.id')
            ->get(['l.name_ar', 'l.name_en', 'l.variant_values', 'l.code', 'l.is_gift', 'rl.quantity', 'rl.received_quantity'])->all()));
    }

    public function returnPhotos(string $returnId): int
    {
        return $this->db->table('sales.return_photos')->where('return_id', $returnId)->count();
    }
}
