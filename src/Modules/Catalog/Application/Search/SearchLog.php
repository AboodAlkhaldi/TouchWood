<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Search;

use Carbon\CarbonImmutable;

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
}
