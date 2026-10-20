<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\Model\ReturnState;
use Modules\Sales\Domain\Repository\Returns;
use stdClass;

/** `Returns` in SQL, no Eloquent model (sales.md §5). */
final readonly class DatabaseReturns implements Returns
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function orderOf(string $returnId): ?array
    {
        $row = $this->db->table('sales.returns')->where('id', strtolower($returnId))->first(['order_id', 'store_id']);

        return $row instanceof stdClass ? [(string) $row->order_id, (string) $row->store_id] : null;
    }

    public function lock(string $returnId): ?ReturnState
    {
        $row = $this->db->table('sales.returns')->where('id', strtolower($returnId))->lockForUpdate()->first(['id', 'order_id', 'store_id', 'status']);

        return $row instanceof stdClass ? new ReturnState((string) $row->id, (string) $row->order_id, (string) $row->store_id, (string) $row->status) : null;
    }

    public function deadline(string $orderId): ?DateTimeImmutable
    {
        $at = $this->db->table('sales.orders')->where('id', $orderId)->value('return_deadline_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }

    public function returnable(string $orderId): array
    {
        $counted = $this->db->table('sales.return_lines AS rl')->join('sales.returns AS r', 'r.id', '=', 'rl.return_id')
            ->where('rl.order_id', $orderId)
            ->groupBy('rl.order_line_id')
            ->selectRaw(<<<'SQL'
                rl.order_line_id,
                SUM(CASE WHEN r.status IN ('REQUESTED', 'ACCEPTED') THEN rl.quantity
                         WHEN r.status IN ('RECEIVED', 'COMPLETED') THEN rl.received_quantity
                         ELSE 0 END) AS counted
                SQL)
            ->pluck('counted', 'order_line_id');
        $lines = [];

        foreach ($this->db->table('sales.order_lines')->where('order_id', $orderId)->orderBy('id')->get(['id', 'quantity']) as $line) {
            $lines[(string) $line->id] = ['quantity' => (int) $line->quantity, 'counted' => (int) ($counted[$line->id] ?? 0)];
        }

        return $lines;
    }

    public function request(string $returnId, string $orderId, string $storeId, string $reason, ?string $reasonOther, array $lines, DateTimeImmutable $at): void
    {
        $this->db->table('sales.returns')->insert([
            'id' => $returnId, 'order_id' => $orderId, 'store_id' => $storeId, 'status' => ReturnState::REQUESTED, 'reason' => $reason,
            'reason_other' => $reasonOther, 'requested_at' => Moment::of($at),
        ]);

        $rows = [];

        foreach ($lines as $lineId => $quantity) {
            $rows[] = ['return_id' => $returnId, 'order_id' => $orderId, 'order_line_id' => $lineId, 'quantity' => $quantity];
        }

        $this->db->table('sales.return_lines')->insert($rows);
    }

    public function withdraw(string $returnId, DateTimeImmutable $at): void
    {
        $this->db->table('sales.returns')->where('id', $returnId)->update(['status' => ReturnState::WITHDRAWN, 'withdrawn_at' => Moment::of($at)]);
    }

    public function decide(string $returnId, string $status, ?string $staffReason, string $by, DateTimeImmutable $at): void
    {
        $this->db->table('sales.returns')->where('id', $returnId)->update([
            'status' => $status, 'staff_reason' => $staffReason, 'decided_by' => $by, 'decided_at' => Moment::of($at),
        ]);
    }

    public function close(string $returnId, DateTimeImmutable $at): void
    {
        $this->db->table('sales.returns')->where('id', $returnId)->update(['status' => ReturnState::CLOSED, 'closed_at' => Moment::of($at)]);
    }

    public function lines(string $returnId): array
    {
        $lines = [];

        foreach ($this->db->table('sales.return_lines AS rl')->join('sales.order_lines AS l', 'l.id', '=', 'rl.order_line_id')
            ->where('rl.return_id', $returnId)->orderBy('rl.order_line_id')
            ->get(['rl.order_line_id', 'l.variant_id', 'l.is_gift', 'rl.quantity', 'rl.received_quantity', 'rl.restocked_quantity']) as $row) {
            $lines[(string) $row->order_line_id] = [
                'variantId' => (string) $row->variant_id, 'gift' => (bool) $row->is_gift, 'asked' => (int) $row->quantity,
                'received' => $row->received_quantity === null ? null : (int) $row->received_quantity,
                'restocked' => $row->restocked_quantity === null ? null : (int) $row->restocked_quantity,
            ];
        }

        return $lines;
    }

    public function receive(string $returnId, array $lines, DateTimeImmutable $at): void
    {
        foreach ($lines as $lineId => [$received, $restocked]) {
            $this->db->table('sales.return_lines')->where('return_id', $returnId)->where('order_line_id', $lineId)
                ->update(['received_quantity' => $received, 'restocked_quantity' => $restocked]);
        }

        $this->db->table('sales.returns')->where('id', $returnId)->update(['status' => ReturnState::RECEIVED, 'received_at' => Moment::of($at)]);
    }

    public function forRefund(string $orderId): array
    {
        $order = $this->db->table('sales.orders')->where('id', $orderId)->first(['shipping_fee_minor', 'vat_minor']);
        $lines = [];
        $linesVat = 0;

        foreach ($this->db->table('sales.order_lines')->where('order_id', $orderId)->orderBy('id')
            ->get(['id', 'quantity', 'total_minor', 'coupon_share_minor', 'points_share_minor', 'vat_share_minor', 'is_gift']) as $row) {
            $lines[(string) $row->id] = [
                'quantity' => (int) $row->quantity, 'total' => (int) $row->total_minor, 'coupon' => (int) $row->coupon_share_minor,
                'points' => (int) $row->points_share_minor, 'vat' => (int) $row->vat_share_minor, 'gift' => (bool) $row->is_gift,
            ];
            $linesVat += (int) $row->vat_share_minor;
        }

        $before = $this->db->table('sales.return_lines AS rl')->join('sales.returns AS r', 'r.id', '=', 'rl.return_id')
            ->where('rl.order_id', $orderId)->where('r.status', ReturnState::COMPLETED)
            ->groupBy('rl.order_line_id')->selectRaw('rl.order_line_id, SUM(rl.received_quantity) AS pieces')->pluck('pieces', 'order_line_id');

        return [
            'lines' => $lines,
            'before' => array_map('intval', $before->all()),
            'shippingRefunded' => $this->db->table('sales.returns')->where('order_id', $orderId)->where('shipping_refunded', true)->exists(),
            'shippingFee' => (int) ($order->shipping_fee_minor ?? 0),
            // The shipping's VAT share: the order's VAT less what its lines carry (§1.8, the shares).
            'shippingVat' => (int) ($order->vat_minor ?? 0) - $linesVat,
        ];
    }

    public function complete(string $returnId, int $refundDue, bool $shippingRefunded, DateTimeImmutable $at): void
    {
        $this->db->table('sales.returns')->where('id', $returnId)->update([
            'status' => ReturnState::COMPLETED, 'completed_at' => Moment::of($at), 'refund_due_minor' => $refundDue, 'shipping_refunded' => $shippingRefunded,
        ]);
    }

    public function photos(string $returnId): int
    {
        return $this->db->table('sales.return_photos')->where('return_id', $returnId)->count();
    }

    public function addPhoto(string $returnId, string $mediaId, int $position): void
    {
        $this->db->table('sales.return_photos')->insert(['return_id' => $returnId, 'media_id' => $mediaId, 'position' => $position]);
    }
}
