<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Promotions\Domain\Model\OrderFact;
use Modules\Promotions\Domain\Repository\OrderFacts;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/** `OrderFacts` in SQL: one row per placed order, written once — the first of two at once wins. */
final readonly class DatabaseOrderFacts implements OrderFacts
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function of(string $orderId): ?OrderFact
    {
        if (! Ulids::valid($orderId)) {
            return null;
        }

        $row = $this->db->table('promotions.order_facts')->where('order_id', strtolower($orderId))->first();

        return $row instanceof stdClass
            ? new OrderFact((string) $row->order_id, (string) $row->store_id, (string) $row->customer_id, (int) $row->ceiling_percent)
            : null;
    }

    public function record(string $orderId, string $storeId, string $customerId, DateTimeImmutable $placedAt, int $ceilingPercent): OrderFact
    {
        // ON CONFLICT: a second write of the same order — a retry, or itself at the same moment — waits
        // for the first and leaves its row as it is.
        $this->db->table('promotions.order_facts')->insertOrIgnore([
            'order_id' => $orderId,
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'placed_at' => Moment::of($placedAt),
            'ceiling_percent' => $ceilingPercent,
        ]);

        return $this->of($orderId) ?? throw new LogicException("The order {$orderId} was recorded and cannot be read.");
    }

    public function whoOrdered(string $storeId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return array_values(array_map(strval(...), $this->db->table('promotions.order_facts')
            ->where('store_id', $storeId)
            ->whereIn('customer_id', $customerIds)
            ->distinct()
            ->orderBy('customer_id')
            ->pluck('customer_id')
            ->all()));
    }

    public function lastOrderBefore(string $storeId, DateTimeImmutable $cutoff, ?string $after, int $limit): array
    {
        return array_values(array_map(strval(...), $this->db->table('promotions.order_facts')
            ->where('store_id', $storeId)
            ->when($after !== null, fn ($query) => $query->where('customer_id', '>', $after))
            ->groupBy('customer_id')
            ->havingRaw('max(placed_at) < ?::timestamptz', [Moment::of($cutoff)])
            ->orderBy('customer_id')
            ->limit($limit)
            ->pluck('customer_id')
            ->all()));
    }

    public function atLeastOrdersSince(string $storeId, DateTimeImmutable $since, int $orders, ?string $after, int $limit): array
    {
        return array_values(array_map(strval(...), $this->db->table('promotions.order_facts')
            ->where('store_id', $storeId)
            ->where('placed_at', '>=', Moment::of($since))
            ->when($after !== null, fn ($query) => $query->where('customer_id', '>', $after))
            ->groupBy('customer_id')
            ->havingRaw('count(*) >= ?', [$orders])
            ->orderBy('customer_id')
            ->limit($limit)
            ->pluck('customer_id')
            ->all()));
    }
}
