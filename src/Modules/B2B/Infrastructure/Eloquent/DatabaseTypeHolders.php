<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\B2B\Application\Query\ViewTypeLists\TypeHolders;

/**
 * How many companies hold each company type of one store, in one grouped query (b2b.md §4.6). Read
 * from the types' own store, not the companies' home store, so it counts every holder of the list's
 * types whichever column says where a company applied (amendment 18).
 */
final readonly class DatabaseTypeHolders implements TypeHolders
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function countsFor(string $storeId): array
    {
        if (! Ulids::valid($storeId)) {
            return [];
        }

        $counts = [];

        $rows = $this->db->table('b2b.companies')
            ->join('b2b.company_types', 'b2b.company_types.id', '=', 'b2b.companies.company_type_id')
            ->where('b2b.company_types.store_id', strtolower($storeId))
            ->groupBy('b2b.companies.company_type_id')
            ->select(['b2b.companies.company_type_id'])
            ->selectRaw('count(*) as holders')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->company_type_id] = (int) $row->holders;
        }

        return $counts;
    }
}
