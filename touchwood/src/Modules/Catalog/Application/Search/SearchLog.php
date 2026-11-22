<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Search;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Catalog\Public\Dto\SearchSummaryDto;

/**
 * The search log (catalog.md §1.11, §5.4): each submitted search — the words as normalised, the
 * store, the language, how many it found and when. **No customer, no IP.**
 */
interface SearchLog
{
    public function record(string $storeId, string $locale, string $query, int $results): void;

    /**
     * @return int how many entries went
     */
    public function prune(CarbonImmutable $before): int;

    /**
     * The searches from `from` up to `to` (not included), by phrase, store and language, most searched
     * first (amendment 37): one store's, or every store's with none. A page of at most `limit` after
     * `after`, the cursor of an earlier page; one that is not one starts at the top.
     */
    public function summary(?string $storeId, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after, int $limit): SearchSummaryDto;
}
