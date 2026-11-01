<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Query\Staff\StaffReads;
use Shared\Domain\Text\LatinDigits;
use stdClass;

/** `StaffReads` in SQL (sales.md §3, §5). */
final readonly class DatabaseStaffReads implements StaffReads
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function orders(?array $storeIds, string $filter, ?string $afterOrderId, int $limit, ?string $q = null, ?string $customerId = null): array
    {
        $query = $this->db->table('sales.orders AS o')
            ->orderByDesc('o.placed_at')->orderByDesc('o.id')->limit($limit)
            ->select(['o.id', 'o.number', 'o.store_id', 'o.customer_id', 'o.placed_at', 'o.status', 'o.shipment_status', 'o.currency_code',
                'o.order_total_minor', 'o.due_minor', 'o.paid_minor', 'o.refunded_minor', 'o.short_of_stock_at', 'o.may_ship_unpaid']);

        if ($storeIds !== null) {
            $query->whereIn('o.store_id', $storeIds);
        }

        match ($filter) {
            self::UNPAID => $query->whereRaw('o.paid_minor - o.refunded_minor < o.due_minor'),
            self::SHORT => $query->whereNotNull('o.short_of_stock_at'),
            self::WAITING_APPROVAL => $query->where('o.status', 'PENDING_APPROVAL'),
            self::TO_SHIP => $query->where('o.status', 'APPROVED')->where('o.shipment_status', 'NOT_SHIPPED'),
            self::SHIPPED => $query->where('o.shipment_status', 'SHIPPED'),
            self::TRANSFERS_TO_CHECK => $query->where('o.way_to_pay', 'BANK_TRANSFER')->whereRaw('o.paid_minor - o.refunded_minor < o.due_minor')
                ->whereExists(fn ($documents) => $documents->from('sales.transfer_documents AS d')->whereColumn('d.order_id', 'o.id')),
            default => $query,
        };

        if ($customerId !== null) {
            $query->where('o.customer_id', $customerId);
        }

        if ($q !== null) {
            // Any part of the number or the name as placed, any case, its digits read as Latin. A phone only when the
            // box holds a phone (digits, spaces, + - and brackets; 4 digits at least), by its digits, leading zeros
            // dropped: 0501234567 typed finds +966501234567 kept.
            $latin = LatinDigits::of($q);
            $text = '%'.addcslashes(mb_strtolower($latin), '%_\\').'%';
            $digits = preg_match('/^[0-9\s()+-]+$/', $latin) === 1 ? ltrim(preg_replace('/[^0-9]/', '', $latin) ?? '', '0') : '';
            $query->where(fn ($found) => $found->whereRaw('lower(o.number) LIKE ?', [$text])->orWhereRaw('lower(o.customer_name) LIKE ?', [$text])
                ->when(strlen($digits) >= 4, fn ($phone) => $phone->orWhereRaw("regexp_replace(o.customer_phone, '[^0-9]', '', 'g') LIKE ?", ['%'.$digits.'%'])));
        }

        if ($afterOrderId !== null) {
            $after = $this->db->table('sales.orders')->where('id', $afterOrderId)->first(['placed_at', 'id']);

            if (! $after instanceof stdClass) {
                return [];
            }

            $query->whereRaw('(o.placed_at, o.id) < (?, ?)', [(string) $after->placed_at, (string) $after->id]);
        }

        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $query->get()->all()));
    }

    public function order(string $orderId): ?array
    {
        $row = $this->db->table('sales.orders')->where('id', $orderId)->first();

        return $row instanceof stdClass ? (array) $row : null;
    }

    public function money(string $orderId): array
    {
        $columns = ['id', 'amount_minor', 'way', 'reference', 'note', 'happened_on', 'recorded_by', 'recorded_at', 'voided_by', 'voided_at', 'void_reason'];
        $payments = $this->db->table('sales.payments')->where('order_id', $orderId)->selectRaw("'PAYMENT' AS kind")->addSelect($columns);
        $refunds = $this->db->table('sales.refunds')->where('order_id', $orderId)->selectRaw("'REFUND' AS kind")->addSelect($columns);

        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $payments->unionAll($refunds)->orderBy('recorded_at')->orderBy('id')->get()->all()));
    }

    public function history(string $orderId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $this->db->table('sales.order_history')
            ->where('order_id', $orderId)->orderBy('id')->get(['kind', 'edit_id', 'by', 'at', 'detail'])->all()));
    }

    public function edits(string $orderId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $this->db->table('sales.order_edits')
            ->where('order_id', $orderId)->orderBy('at')->orderBy('id')->get(['id', 'reason', 'by', 'at'])->all()));
    }

    public function ticks(string $orderId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $this->db->table('sales.order_ticks')
            ->where('order_id', $orderId)->orderBy('ticked_at')->orderBy('variant_id')->get(['variant_id', 'ticked_by', 'ticked_at'])->all()));
    }

    public function transferDocuments(string $orderId): array
    {
        return array_values(array_map(static fn (stdClass $row): array => (array) $row, $this->db->table('sales.transfer_documents')
            ->where('order_id', $orderId)->orderBy('position')->get(['media_id', 'position', 'uploaded_at'])->all()));
    }

    public function storesWithUnfinished(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        // Unpaid, partly paid or refund due all hold other than is due (OrderStatuses::payment).
        return array_values(array_map('strval', $this->db->table('sales.orders AS o')->whereIn('o.store_id', $storeIds)
            ->where(fn ($unfinished) => $unfinished->whereIn('o.status', ['PENDING_APPROVAL', 'APPROVED'])
                ->orWhereRaw('o.paid_minor - o.refunded_minor <> o.due_minor')
                ->orWhereExists(fn ($open) => $open->from('sales.returns AS r')->whereColumn('r.order_id', 'o.id')
                    ->whereIn('r.status', ['REQUESTED', 'ACCEPTED', 'RECEIVED'])))
            ->distinct()->pluck('o.store_id')->all()));
    }
}
