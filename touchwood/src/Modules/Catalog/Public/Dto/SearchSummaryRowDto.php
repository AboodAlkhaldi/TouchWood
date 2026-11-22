<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * **One phrase of `CatalogApi::searchSummary`** (catalog.md amendment 37): the words as the log keeps them
 * (normalised), in one store and language, how many times they were submitted in the range and how many of
 * those found nothing. No person, ever (§1.11).
 */
final readonly class SearchSummaryRowDto
{
    public function __construct(
        public string $query,
        public string $storeId,
        public string $locale,
        public int $searches,
        public int $noResults,
    ) {}
}
