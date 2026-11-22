<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * Searches that found nothing, grouped (catalog.md §1.11, §4.4 S7): the words as search read them, the
 * store and the language, how many times, and when last. No person: the log keeps none.
 */
final readonly class NoResultSearchRow
{
    public function __construct(
        public string $query,
        public string $storeId,
        public string $locale,
        public int $times,
        public string $lastSearchedAt,
    ) {}
}
