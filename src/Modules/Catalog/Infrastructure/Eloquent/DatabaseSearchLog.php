<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Application\Search\SearchLog;

final readonly class DatabaseSearchLog implements SearchLog
{
    private const string TABLE = 'catalog.search_log';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function record(string $storeId, string $locale, string $query, int $results): void
    {
        // The time is the database's own (searched_at DEFAULT now()).
        $this->db->table(self::TABLE)->insert(['store_id' => $storeId, 'locale' => $locale, 'query' => $query, 'results' => $results]);
    }

    public function prune(CarbonImmutable $before): int
    {
        return $this->db->table(self::TABLE)->where('searched_at', '<', $before)->delete();
    }
}
