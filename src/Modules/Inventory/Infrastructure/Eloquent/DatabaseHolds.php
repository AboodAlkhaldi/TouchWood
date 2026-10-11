<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Domain\Repository\Holds;
use Modules\Inventory\Domain\ValueObject\Hold;
use Modules\Inventory\Domain\ValueObject\HoldLine;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * `inventory.holds`, `hold_lines` and `return_lines` (inventory.md §5).
 */
final readonly class DatabaseHolds implements Holds
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function lockForOrder(string $orderId): ?Hold
    {
        $hold = $this->db->table('inventory.holds')->where('order_id', strtolower(trim($orderId)))->lockForUpdate()->first();

        if (! $hold instanceof stdClass) {
            return null;
        }

        $lines = [];

        foreach ($this->db->table('inventory.hold_lines')->where('hold_id', $hold->id)->orderBy('variant_id')->orderBy('gift')->get() as $row) {
            $line = new HoldLine((string) $row->variant_id, (bool) $row->gift, (int) $row->quantity, (bool) $row->counts_on_stock, (int) $row->shipped, (string) $row->ended);
            $lines[$line->key()] = $line;
        }

        return new Hold(
            (string) $hold->id,
            (string) $hold->store_id,
            (string) $hold->order_id,
            $hold->expires_at === null ? null : CarbonImmutable::parse((string) $hold->expires_at)->toDateTimeImmutable(),
            $lines,
        );
    }

    public function create(Hold $hold): void
    {
        $this->db->table('inventory.holds')->insert([
            'id' => $hold->id,
            'store_id' => $hold->storeId,
            'order_id' => $hold->orderId,
            'expires_at' => $hold->expiresAt?->format('Y-m-d H:i:s.uP'),
            'created_at' => CarbonImmutable::now(),
        ]);

        foreach ($hold->lines as $line) {
            $this->saveLine($hold->id, $line);
        }
    }

    public function saveLine(string $holdId, HoldLine $line): void
    {
        $this->db->table('inventory.hold_lines')->upsert([[
            'hold_id' => $holdId,
            'variant_id' => $line->variantId,
            'gift' => $line->gift,
            'quantity' => $line->quantity,
            'counts_on_stock' => $line->countsOnStock,
            'shipped' => $line->shipped,
            'ended' => $line->ended,
            'ended_at' => $line->ended === HoldLine::OPEN ? null : CarbonImmutable::now(),
        ]], ['hold_id', 'variant_id', 'gift'], ['quantity', 'counts_on_stock', 'shipped', 'ended', 'ended_at']);
    }

    public function expiredOrderIds(DateTimeImmutable $at): array
    {
        return array_values(array_map('strval', $this->db->table('inventory.holds as h')
            ->where('h.expires_at', '<=', $at->format('Y-m-d H:i:s.uP'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('inventory.hold_lines as l')->whereColumn('l.hold_id', 'h.id')->where('l.ended', HoldLine::OPEN))
            ->orderBy('h.expires_at')
            ->pluck('h.order_id')
            ->all()));
    }

    public function ordersHolding(StoreId $store, string $variantId): array
    {
        return array_values(array_map('strval', $this->db->table('inventory.holds as h')
            ->join('inventory.hold_lines as l', 'l.hold_id', '=', 'h.id')
            ->where('h.store_id', $store->value)
            ->where('l.variant_id', $variantId)
            ->where('l.counts_on_stock', true)
            ->where('l.ended', HoldLine::OPEN)
            ->distinct()
            ->orderBy('h.order_id')
            ->pluck('h.order_id')
            ->all()));
    }

    public function returnCounted(string $returnId, string $variantId, bool $gift): bool
    {
        return $this->db->table('inventory.return_lines')->where('return_id', $returnId)->where('variant_id', $variantId)->where('gift', $gift)->exists();
    }

    public function recordReturn(string $returnId, string $variantId, bool $gift, StoreId $store, string $orderId, int $quantity): void
    {
        $this->db->table('inventory.return_lines')->insert([
            'return_id' => $returnId,
            'variant_id' => $variantId,
            'gift' => $gift,
            'store_id' => $store->value,
            'order_id' => $orderId,
            'quantity' => $quantity,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
