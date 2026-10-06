<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Modules\B2B\Application\Query\ListCompanies\CompanyReader;
use Modules\B2B\Application\Query\ListCompanies\CompanySummary;

/**
 * The staff company list (b2b.md §3.2, amendment 10(g)). Every filter is in the WHERE clause and the
 * total is counted on it, so a page never comes back short and the total counts nothing the reader
 * may not see (lesson 69).
 */
final readonly class DatabaseCompanyReader implements CompanyReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function companies(?array $homeStoreIds, ?string $search, ?string $status, int $page, int $perPage): array
    {
        $query = $this->query($homeStoreIds, $search, $status);
        $total = (clone $query)->count();

        $rows = $query
            ->select(['c.id', 'c.name', 'c.status', 'c.home_store_id', 'c.status_changed_at', 'a.submitted_at as waiting_since'])
            ->selectRaw('(t.id is not null and t.is_active = false) as type_stale')
            // Waiting companies first, the oldest sent first; then the others, the latest status
            // change first. The id last, so a page boundary never moves between two reads.
            ->orderByRaw("case when c.status = 'PENDING' then 0 else 1 end")
            ->orderByRaw("case when c.status = 'PENDING' then a.submitted_at end asc")
            ->orderByRaw('c.status_changed_at desc nulls last')
            ->orderBy('c.id')
            ->forPage($page, $perPage)
            ->get();

        $companies = [];

        foreach ($rows as $row) {
            $companies[] = new CompanySummary(
                (string) $row->id,
                (string) $row->name,
                (string) $row->status,
                (string) $row->home_store_id,
                self::time($row->waiting_since),
                (bool) $row->type_stale,
                self::time($row->status_changed_at),
            );
        }

        return [$companies, $total];
    }

    public function statusCounts(?array $homeStoreIds): array
    {
        $query = $this->db->table('b2b.companies as c');

        if ($homeStoreIds !== null) {
            $query->whereIn('c.home_store_id', array_map(strtolower(...), $homeStoreIds));
        }

        $counts = [];

        foreach ($query->selectRaw('c.status, count(*) as total')->groupBy('c.status')->get() as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * @param  list<string>|null  $homeStoreIds
     */
    private function query(?array $homeStoreIds, ?string $search, ?string $status): Builder
    {
        $query = $this->db->table('b2b.companies as c')
            // The one application waiting for a decision, if any (one open per account).
            ->leftJoin('b2b.applications as a', static function (JoinClause $join): void {
                $join->on('a.company_id', '=', 'c.id')->where('a.state', '=', 'SUBMITTED');
            })
            ->leftJoin('b2b.company_types as t', 't.id', '=', 'a.company_type_id');

        if ($homeStoreIds !== null) {
            $query->whereIn('c.home_store_id', array_map(strtolower(...), $homeStoreIds));
        }

        if ($status !== null) {
            $query->where('c.status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $like = self::like($search);
            // A reference is quoted whole — read out over the phone, say — so it is matched whole,
            // ignoring case, on its own unique index (amendment 14(g)).
            $reference = mb_strtoupper(trim($search));

            $query->where(static function (Builder $where) use ($like, $reference): void {
                $where->whereRaw('lower(c.name) like ?', [$like])
                    ->orWhereRaw('lower(c.cr_number) like ?', [$like])
                    ->orWhereRaw('lower(c.tax_number) like ?', [$like])
                    ->orWhereExists(static function (Builder $sent) use ($reference): void {
                        $sent->selectRaw('1')
                            ->from('b2b.applications as r')
                            ->whereColumn('r.company_id', 'c.id')
                            ->where('r.reference', $reference);
                    });
            });
        }

        return $query;
    }

    /**
     * What was typed, found anywhere in the value — a % or _ in it meaning itself.
     */
    private static function like(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower(trim($search))).'%';
    }

    private static function time(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->format(DATE_ATOM);
    }
}
