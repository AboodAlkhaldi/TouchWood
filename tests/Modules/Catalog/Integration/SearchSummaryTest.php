<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\SearchSummaryDto;
use Modules\Catalog\Public\Dto\SearchSummaryRowDto;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| `CatalogApi::searchSummary` (catalog.md amendment 37; ops.md §2.3): the search log by phrase, store and
| language over a range, most searched first, keyset-paged; one store's or every store's. No person.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** One log entry, at a moment of its own. */
function searchSummaryLog(string $store, string $locale, string $query, int $results, string $at): void
{
    DB::table('catalog.search_log')->insert(['store_id' => Fx::storeId($store), 'locale' => $locale, 'query' => $query, 'results' => $results, 'searched_at' => $at]);
}

/** @return list<array{string, string, string, int, int}> */
function searchSummaryRows(SearchSummaryDto $page): array
{
    return array_map(static fn (SearchSummaryRowDto $row): array => [$row->query, $row->storeId, $row->locale, $row->searches, $row->noResults], $page->rows);
}

function searchSummaryRead(?string $store, string $from, string $to, ?string $after = null, int $limit = 100): SearchSummaryDto
{
    return app(CatalogApi::class)->searchSummary(
        $store === null ? null : StoreId::fromString(Fx::storeId($store)),
        CarbonImmutable::parse($from), CarbonImmutable::parse($to), $after, $limit,
    );
}

it('groups the range by phrase, store and language, most searched first, counting those that found nothing', function () {
    searchSummaryLog('sa', 'en', 'oak table', 4, '2026-10-01 09:00:00+00');
    searchSummaryLog('sa', 'en', 'oak table', 0, '2026-10-01 10:00:00+00');
    searchSummaryLog('sa', 'en', 'oak table', 2, '2026-10-01 23:59:59+00');
    searchSummaryLog('sa', 'ar', 'oak table', 0, '2026-10-01 11:00:00+00');
    searchSummaryLog('sa', 'en', 'chair', 0, '2026-10-01 12:00:00+00');
    searchSummaryLog('sa', 'en', 'chair', 0, '2026-10-01 13:00:00+00');
    searchSummaryLog('ae', 'en', 'oak table', 1, '2026-10-01 14:00:00+00');
    // Outside the range: the moment before it, and its end, which is not included.
    searchSummaryLog('sa', 'en', 'oak table', 1, '2026-09-30 23:59:59+00');
    searchSummaryLog('sa', 'en', 'oak table', 1, '2026-10-02 00:00:00+00');

    $sa = Fx::storeId('sa');
    $ae = Fx::storeId('ae');

    expect(searchSummaryRows(searchSummaryRead('sa', '2026-10-01 00:00:00+00', '2026-10-02 00:00:00+00')))->toBe([
        ['oak table', $sa, 'en', 3, 1],
        ['chair', $sa, 'en', 2, 2],
        ['oak table', $sa, 'ar', 1, 1],
    ])
        ->and(array_column(searchSummaryRows(searchSummaryRead(null, '2026-10-01 00:00:00+00', '2026-10-02 00:00:00+00')), 1))
        ->toBe([$sa, $sa, ...(strcmp($ae, $sa) < 0 ? [$ae, $sa] : [$sa, $ae])]);
});

it('pages the phrases with a cursor, the same rows as one long page, and starts at the top for one that is not one', function () {
    foreach (['a', 'b', 'c', 'd', 'e'] as $i => $word) {
        foreach (range(0, $i % 3) as $n) {
            searchSummaryLog('sa', 'en', $word, $n, '2026-10-01 09:00:00+00');
        }
    }

    $all = searchSummaryRows(searchSummaryRead('sa', '2026-10-01', '2026-10-02'));
    $pages = [];
    $after = null;

    do {
        $page = searchSummaryRead('sa', '2026-10-01', '2026-10-02', $after, 2);
        $pages = [...$pages, ...searchSummaryRows($page)];
        $after = $page->next;
    } while ($after !== null);

    expect(array_column($all, 0))->toBe(['c', 'b', 'e', 'a', 'd'])
        ->and($pages)->toBe($all)
        ->and(searchSummaryRows(searchSummaryRead('sa', '2026-10-01', '2026-10-02', 'not-a-cursor', 2)))->toBe(array_slice($all, 0, 2))
        ->and(searchSummaryRead('sa', '2026-10-01', '2026-10-02', null, 5)->next)->toBeNull();
});

it('answers an empty or reversed range, and a store with no searches, with nothing', function () {
    searchSummaryLog('sa', 'en', 'oak', 1, '2026-10-01 09:00:00+00');

    expect(searchSummaryRead('sa', '2026-10-02', '2026-10-01')->rows)->toBe([])
        ->and(searchSummaryRead('sa', '2026-10-01', '2026-10-01')->rows)->toBe([])
        ->and(searchSummaryRead('eg', '2026-10-01', '2026-10-02')->rows)->toBe([]);
});

it('reads one query however many phrases', function () {
    foreach (range(1, 30) as $n) {
        searchSummaryLog('sa', 'en', "word {$n}", $n % 2, '2026-10-01 09:00:00+00');
    }

    DB::enableQueryLog();
    $page = searchSummaryRead(null, '2026-10-01', '2026-10-02', null, 1000);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect([count($page->rows), $queries])->toBe([30, 1]);
});
