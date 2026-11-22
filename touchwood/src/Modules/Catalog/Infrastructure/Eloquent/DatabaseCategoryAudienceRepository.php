<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Domain\Repository\CategoryAudienceRepository;
use Modules\Catalog\Domain\ValueObject\CategoryAudience;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * `catalog.store_category_audiences` (catalog.md §5.2): one row a store and a category someone
 * switched there; none, both kinds of account see it. Ids that are not ULIDs find nothing without a
 * query.
 */
final readonly class DatabaseCategoryAudienceRepository implements CategoryAudienceRepository
{
    private const string TABLE = 'catalog.store_category_audiences';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function in(string $storeId, array $categoryIds): array
    {
        $ids = self::ids($categoryIds);
        $audiences = array_fill_keys($ids, CategoryAudience::both());

        if ($ids === [] || ! Ulids::valid($storeId)) {
            return $audiences;
        }

        foreach ($this->db->table(self::TABLE)->where('store_id', strtolower($storeId))->whereIn('category_id', $ids)->get(['category_id', 'for_individuals', 'for_companies']) as $row) {
            $audiences[(string) $row->category_id] = CategoryAudience::of((bool) $row->for_individuals, (bool) $row->for_companies);
        }

        return $audiences;
    }

    public function set(string $storeId, array $audiences): void
    {
        if ($audiences === []) {
            return;
        }

        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($audiences as $categoryId => $audience) {
            $rows[] = [
                'store_id' => strtolower($storeId),
                'category_id' => strtolower((string) $categoryId),
                'for_individuals' => $audience->forIndividuals,
                'for_companies' => $audience->forCompanies,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->db->table(self::TABLE)->upsert($rows, ['store_id', 'category_id'], ['for_individuals', 'for_companies', 'updated_at']);
    }

    public function inEveryStore(array $categoryIds): array
    {
        $ids = self::ids($categoryIds);
        $audiences = [];

        if ($ids === []) {
            return [];
        }

        foreach ($this->db->table(self::TABLE)->whereIn('category_id', $ids)->orderBy('store_id')->orderBy('category_id')->get(['store_id', 'category_id', 'for_individuals', 'for_companies']) as $row) {
            $audiences[(string) $row->store_id][(string) $row->category_id] = CategoryAudience::of((bool) $row->for_individuals, (bool) $row->for_companies);
        }

        return $audiences;
    }

    /**
     * @param  list<string>  $categoryIds
     * @return list<string>
     */
    private static function ids(array $categoryIds): array
    {
        return array_values(array_unique(array_map('strtolower', array_filter($categoryIds, Ulids::valid(...)))));
    }
}
