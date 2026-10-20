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

    public function photos(string $returnId): int
    {
        return $this->db->table('sales.return_photos')->where('return_id', $returnId)->count();
    }

    public function addPhoto(string $returnId, string $mediaId, int $position): void
    {
        $this->db->table('sales.return_photos')->insert(['return_id' => $returnId, 'media_id' => $mediaId, 'position' => $position]);
    }
}
