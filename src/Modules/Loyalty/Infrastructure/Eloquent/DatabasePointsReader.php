<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Loyalty\Application\Query\HolderRow;
use Modules\Loyalty\Application\Query\LiveLotRow;
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

    public function holders(string $storeId, DateTimeImmutable $now, ?array $customerIds, ?array $after, int $limit): array
    {
        if ($customerIds === []) {
            return [];
        }

        // The live points of every account in the store: the lots still holding points whose date
        // has not passed (§1.1), summed. An account with none is listed too, after the others.
        $live = $this->db->table('loyalty.lots')
            ->join('loyalty.accounts', 'loyalty.accounts.id', '=', 'loyalty.lots.account_id')
            ->where('loyalty.accounts.store_id', strtolower($storeId))
            ->where('loyalty.lots.points_left', '>', 0)
            ->where('loyalty.lots.expires_at', '>', Moment::of($now))
            ->groupBy('loyalty.lots.account_id')
            ->select(['loyalty.lots.account_id'])
            ->selectRaw('sum(loyalty.lots.points_left) as points');

        $query = $this->db->table('loyalty.accounts')
            ->leftJoinSub($live, 'live', 'live.account_id', '=', 'loyalty.accounts.id')
            ->where('loyalty.accounts.store_id', strtolower($storeId))
            ->select(['loyalty.accounts.id', 'loyalty.accounts.customer_id'])
            ->selectRaw('coalesce(live.points, 0) as live_points')
            // The newest entry, read through the history's own index, one account at a time.
            ->selectRaw('(select max(e.occurred_at) from loyalty.entries e where e.account_id = loyalty.accounts.id) as last_at');

        if ($customerIds !== null) {
            $query->whereIn('loyalty.accounts.customer_id', array_map(strtolower(...), $customerIds));
        }

        if ($after !== null) {
            $query->whereRaw('(coalesce(live.points, 0), loyalty.accounts.id) < (?, ?)', [$after[0], strtolower($after[1])]);
        }

        /** @var list<stdClass> $rows */
        $rows = $query->orderByRaw('coalesce(live.points, 0) desc')->orderByDesc('loyalty.accounts.id')->limit($limit)->get()->all();

        return array_map(
            static fn (stdClass $row): HolderRow => new HolderRow(
                (string) $row->id,
                (string) $row->customer_id,
                (int) $row->live_points,
                $row->last_at === null ? null : new DateTimeImmutable((string) $row->last_at),
            ),
            $rows,
        );
    }

    public function liveLotsOf(array $accountIds, DateTimeImmutable $now): array
    {
        if ($accountIds === []) {
            return [];
        }

        /** @var list<stdClass> $rows */
        $rows = $this->db->table('loyalty.lots')
            ->join('loyalty.accounts', 'loyalty.accounts.id', '=', 'loyalty.lots.account_id')
            ->whereIn('loyalty.lots.account_id', array_map(strtolower(...), $accountIds))
            ->where('loyalty.lots.points_left', '>', 0)
            ->where('loyalty.lots.expires_at', '>', Moment::of($now))
            ->orderBy('loyalty.lots.expires_at')
            ->orderBy('loyalty.lots.id')
            ->get(['loyalty.lots.account_id', 'loyalty.accounts.store_id', 'loyalty.lots.points_left', 'loyalty.lots.expires_at'])
            ->all();

        return array_map(
            static fn (stdClass $row): LiveLotRow => new LiveLotRow(
                (string) $row->account_id,
                (string) $row->store_id,
                (int) $row->points_left,
                new DateTimeImmutable((string) $row->expires_at),
            ),
            $rows,
        );
    }

    public function accountsOf(string $customerId, array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        $accounts = [];

        foreach ($this->db->table('loyalty.accounts')
            ->where('customer_id', strtolower($customerId))
            ->whereIn('store_id', array_map(strtolower(...), $storeIds))
            ->get(['id', 'store_id']) as $row) {
            /** @var stdClass $row */
            $accounts[(string) $row->store_id] = (string) $row->id;
        }

        return $accounts;
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

    public function entry(int $entryId): ?array
    {
        $row = $this->db->table('loyalty.entries as e')
            ->join('loyalty.accounts as a', 'a.id', '=', 'e.account_id')
            ->leftJoin('loyalty.orders as o', 'o.order_id', '=', 'e.order_id')
            ->leftJoin('loyalty.lots as l', 'l.entry_id', '=', 'e.id')
            ->where('e.id', $entryId)
            ->select(['e.id', 'e.store_id', 'a.customer_id', 'e.kind', 'e.points', 'e.reason', 'o.order_number', 'l.expires_at'])
            // The live lots only, as `balance` counts it: one past its date the nightly sweep has not reached yet is gone.
            ->selectRaw('(SELECT coalesce(sum(live.points_left), 0) FROM loyalty.lots AS live WHERE live.account_id = a.id AND live.points_left > 0 AND live.expires_at > ?) AS balance', [Moment::of(CarbonImmutable::now())])
            ->first();

        return $row instanceof stdClass ? [
            'id' => (int) $row->id, 'store_id' => (string) $row->store_id, 'customer_id' => (string) $row->customer_id,
            'kind' => (string) $row->kind, 'points' => (int) $row->points, 'reason' => $row->reason === null ? null : (string) $row->reason,
            'order_number' => $row->order_number === null ? null : (string) $row->order_number,
            'expires_at' => $row->expires_at === null ? null : (string) $row->expires_at, 'balance' => (int) $row->balance,
        ] : null;
    }

    public function expiring(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $afterCustomerId, int $limit): array
    {
        return array_values(array_map(static fn (stdClass $row): array => [(string) $row->customer_id, (int) $row->points], $this->db->table('loyalty.lots as l')
            ->join('loyalty.accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.store_id', strtolower($storeId))
            ->where('l.points_left', '>', 0)
            ->where('l.expires_at', '>=', Moment::of($from))->where('l.expires_at', '<', Moment::of($to))
            ->when($afterCustomerId !== null, fn (Builder $query) => $query->where('a.customer_id', '>', $afterCustomerId))
            ->groupBy('a.customer_id')
            ->orderBy('a.customer_id')
            ->limit($limit)
            ->get(['a.customer_id', $this->db->raw('sum(l.points_left) as points')])
            ->all()));
    }

    public function byKind(string $storeId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(array_map(static fn (stdClass $row): array => [(string) $row->kind, (int) $row->entries, (int) $row->points], $this->db->table('loyalty.entries')
            ->where('store_id', strtolower($storeId))
            ->where('occurred_at', '>=', Moment::of($from))->where('occurred_at', '<', Moment::of($to))
            ->groupBy('kind')
            ->orderBy('kind')
            ->get(['kind', $this->db->raw('count(*) as entries'), $this->db->raw('sum(points) as points')])
            ->all()));
    }
}
