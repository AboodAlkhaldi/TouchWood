<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Modules\Pricing\Domain\ValueObject\WindowOrder;
use Modules\Pricing\Domain\ValueObject\WindowState;
use stdClass;

/**
 * **A page of sales or category discounts** as the screens list them (pricing.md §4.3.3 P2, P3; owner
 * Q7): one state or any; **by state** - running first, the one ending soonest first (no end last); then
 * scheduled, the one starting soonest first; then ended, the one ended last first - or **newest added**.
 *
 * Both orders are one ascending key of three - the state's rank, a number from the dates, the id
 * compared byte by byte (`COLLATE "C"`, lesson 132) - so one keyset serves both: a date ordered
 * downwards is its epoch taken negative. The state is read from the dates and the moment given, never
 * stored (§4.1). Two queries: the rows, and how many match.
 */
final class WindowListing
{
    /** Where "no end" sorts among the ends: after every real one. */
    private const string NO_END = "timestamptz '9999-12-31 00:00:00+00'";

    /**
     * @param  literal-string  $alias  the table's alias in the query
     * @param  array{int, string, string}|null  $after  the last row's keys on the page before
     * @return array{list<stdClass>, int, array{int, string, string}|null} the rows, how many match, the keys of the last row when more follow
     */
    public static function page(Builder $query, string $alias, DateTimeImmutable $now, ?WindowState $state, WindowOrder $order, ?array $after, int $limit): array
    {
        $at = $now->format('Y-m-d H:i:s.uP');

        match ($state) {
            WindowState::Scheduled => $query->where("{$alias}.starts_at", '>', $at),
            WindowState::Running => $query->where("{$alias}.starts_at", '<=', $at)
                ->where(static fn (Builder $end) => $end->whereNull("{$alias}.ends_at")->orWhere("{$alias}.ends_at", '>', $at)),
            WindowState::Ended => $query->whereNotNull("{$alias}.ends_at")->where("{$alias}.ends_at", '<=', $at),
            null => null,
        };

        $total = (clone $query)->count();

        if ($order === WindowOrder::Newest) {
            [$rank, $rankBindings] = ['0', []];
            [$key, $keyBindings] = ["-extract(epoch from {$alias}.created_at)", []];
        } else {
            $scheduled = "{$alias}.starts_at > ?::timestamptz";
            $ended = "({$alias}.ends_at IS NOT NULL AND {$alias}.ends_at <= ?::timestamptz)";
            [$rank, $rankBindings] = ["(CASE WHEN {$scheduled} THEN 1 WHEN {$ended} THEN 2 ELSE 0 END)", [$at, $at]];
            [$key, $keyBindings] = [
                "(CASE WHEN {$scheduled} THEN extract(epoch from {$alias}.starts_at) WHEN {$ended} THEN -extract(epoch from {$alias}.ends_at)"
                ." ELSE extract(epoch from coalesce({$alias}.ends_at, ".self::NO_END.')) END)',
                [$at, $at],
            ];
        }

        if ($after !== null) {
            $query->whereRaw(
                "({$rank}, {$key}, {$alias}.id COLLATE \"C\") > (?::int, ?::numeric, ?)",
                [...$rankBindings, ...$keyBindings, $after[0], $after[1], $after[2]],
            );
        }

        $rows = $query
            ->select("{$alias}.*")
            ->selectRaw("{$rank} AS sort_rank", $rankBindings)
            ->selectRaw("{$key} AS sort_key", $keyBindings)
            ->orderByRaw("sort_rank, sort_key, {$alias}.id COLLATE \"C\"")
            ->limit($limit + 1)
            ->get()
            ->all();

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            $rows,
            $total,
            $more && $last !== null ? [(int) $last->sort_rank, (string) $last->sort_key, (string) $last->id] : null,
        ];
    }
}
