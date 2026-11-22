<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Application\Search\SearchLog;
use Modules\Catalog\Public\Dto\SearchSummaryDto;
use Modules\Catalog\Public\Dto\SearchSummaryRowDto;
use stdClass;

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

    /**
     * One query: the range's entries grouped, then ordered by how many times (most first), the words, the
     * store and the language, compared `COLLATE "C"` byte by byte as every keyset in Catalog is (lesson 132).
     * The cursor is the last row's keys in URL-safe base64, as `DatabaseApiSearches` writes it.
     */
    public function summary(?string $storeId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after, int $limit): SearchSummaryDto
    {
        if ($from >= $to) {
            return new SearchSummaryDto([], null);
        }

        $bindings = [self::moment($from), self::moment($to)];
        $store = '';

        if ($storeId !== null) {
            $store = 'AND store_id = ?';
            $bindings[] = strtolower($storeId);
        }

        $keys = self::cursor($after);
        $past = '';

        if ($keys !== null) {
            $past = 'WHERE (-searches, query COLLATE "C", store_id COLLATE "C", locale COLLATE "C") > (?, ? COLLATE "C", ? COLLATE "C", ? COLLATE "C")';
            array_push($bindings, ...$keys);
        }

        $bindings[] = $limit + 1;

        $rows = $this->db->select(<<<SQL
            WITH phrases AS (
                SELECT query, store_id, locale, count(*) AS searches, count(*) FILTER (WHERE results = 0) AS no_results
                FROM catalog.search_log
                WHERE searched_at >= ? AND searched_at < ? {$store}
                GROUP BY query, store_id, locale
            )
            SELECT query, store_id, locale, searches, no_results FROM phrases {$past}
            ORDER BY -searches, query COLLATE "C", store_id COLLATE "C", locale COLLATE "C"
            LIMIT ?
            SQL, $bindings);

        $more = count($rows) > $limit;
        $rows = array_values(array_map(static fn (stdClass $row): SearchSummaryRowDto => new SearchSummaryRowDto(
            (string) $row->query, (string) $row->store_id, (string) $row->locale, (int) $row->searches, (int) $row->no_results,
        ), array_slice($rows, 0, $limit)));
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new SearchSummaryDto($rows, $more && $last !== null ? self::encode([-$last->searches, $last->query, $last->storeId, $last->locale]) : null);
    }

    /** In UTC with its fraction of a second and its offset, as PostgreSQL is given a moment elsewhere. */
    private static function moment(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    /**
     * @param  list<string|int>  $keys
     */
    private static function encode(array $keys): string
    {
        return rtrim(strtr(base64_encode(json_encode($keys, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /**
     * The keys a cursor holds - minus the times, the words, the store, the language - or null for one that
     * is not one of this order's.
     *
     * @return array{int, string, string, string}|null
     */
    private static function cursor(?string $after): ?array
    {
        if ($after === null || $after === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $after) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($after, '-_', '+/'), true);
        $keys = $json === false ? null : json_decode($json, true);

        if (! is_array($keys) || ! array_is_list($keys) || count($keys) !== 4
            || ! is_int($keys[0]) || ! is_string($keys[1]) || ! is_string($keys[2]) || ! is_string($keys[3])) {
            return null;
        }

        return [$keys[0], $keys[1], $keys[2], $keys[3]];
    }
}
