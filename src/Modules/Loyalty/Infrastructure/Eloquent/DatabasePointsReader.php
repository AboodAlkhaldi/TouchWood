<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Loyalty\Application\Query\PointsHistoryRow;
use Modules\Loyalty\Application\Query\PointsReader;
use Modules\Loyalty\Application\Query\RedemptionRow;
use Modules\Loyalty\Public\Enums\EntryKind;
use Shared\Domain\ValueObject\Money;
use stdClass;

/**
 * The ledger read for the screens (loyalty.md §3) on the query builder: newest first, keyset-paged on
 * (`occurred_at`, `id`) — the history by `entries_history_idx`, the redemptions by
 * `entries_redemptions_idx` (§5).
 */
final readonly class DatabasePointsReader implements PointsReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function history(string $accountId, ?DateTimeImmutable $beforeAt, ?int $beforeId, int $limit): array
    {
        $query = $this->db->table('loyalty.entries')
            ->leftJoin('loyalty.orders', 'loyalty.orders.order_id', '=', 'loyalty.entries.order_id')
            ->where('loyalty.entries.account_id', strtolower($accountId))
            ->select(['loyalty.entries.*', 'loyalty.orders.order_number']);

        return array_map(
            static fn (stdClass $row): PointsHistoryRow => new PointsHistoryRow(
                (int) $row->id,
                EntryKind::from((string) $row->kind),
                (int) $row->points,
                new DateTimeImmutable((string) $row->occurred_at),
                $row->order_number === null ? null : (string) $row->order_number,
                $row->reason === null ? null : (string) $row->reason,
                $row->staff_id === null ? null : (string) $row->staff_id,
            ),
            $this->page($query, $beforeAt, $beforeId, $limit),
        );
    }

    public function redemptions(string $storeId, ?DateTimeImmutable $beforeAt, ?int $beforeId, int $limit): array
    {
        $query = $this->db->table('loyalty.entries')
            ->join('loyalty.orders', 'loyalty.orders.order_id', '=', 'loyalty.entries.order_id')
            ->join('loyalty.accounts', 'loyalty.accounts.id', '=', 'loyalty.entries.account_id')
            ->where('loyalty.entries.store_id', strtolower($storeId))
            ->where('loyalty.entries.kind', EntryKind::Redeemed->value)
            ->select(['loyalty.entries.id', 'loyalty.entries.points', 'loyalty.entries.occurred_at', 'loyalty.orders.order_number', 'loyalty.orders.discount_minor', 'loyalty.orders.currency_code', 'loyalty.accounts.customer_id']);

        return array_map(
            static fn (stdClass $row): RedemptionRow => new RedemptionRow(
                (int) $row->id,
                (string) $row->order_number,
                (string) $row->customer_id,
                -(int) $row->points,
                Money::of((int) $row->discount_minor, (string) $row->currency_code),
                new DateTimeImmutable((string) $row->occurred_at),
            ),
            $this->page($query, $beforeAt, $beforeId, $limit),
        );
    }

    /**
     * @return list<stdClass>
     */
    private function page(Builder $query, ?DateTimeImmutable $beforeAt, ?int $beforeId, int $limit): array
    {
        if ($beforeAt !== null && $beforeId !== null) {
            $query->whereRaw('(loyalty.entries.occurred_at, loyalty.entries.id) < (?::timestamptz, ?)', [Moment::of($beforeAt), $beforeId]);
        }

        /** @var list<stdClass> */
        return $query->orderByDesc('loyalty.entries.occurred_at')->orderByDesc('loyalty.entries.id')->limit($limit)->get()->all();
    }
}
