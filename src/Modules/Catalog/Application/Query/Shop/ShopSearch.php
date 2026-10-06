<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Modules\Catalog\Application\Search\SearchLog;
use Modules\Catalog\Application\Search\SearchTerms;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The shop's search** (catalog.md §1.11): the name in both languages, the search words, the shared
 * word pairs and the names of its categories — never the brand, the code or the description
 * (amendment 5(c), (d), (f), (g)) — ranked in one expression: exact, prefix, nearest to what was
 * typed, then a search word or a pair, then a category's name; ties by sales rank, then newest.
 * What a shopper can order here is searched — a product left in an inactive category included
 * (§1.4) — **of the brands shown in default listings only**: a secondary brand is reached through
 * its own category, never by search (owner, 2026-10-05, amendment 5(k)). Anyone may search; no
 * permission is asked.
 */
final readonly class ShopSearch
{
    /** Shown while the shopper types. */
    public const int SUGGESTIONS = 8;

    public const int RESULTS = 48;

    public const int RESULTS_MAX = 100;

    public function __construct(
        private ShopReader $reader,
        private SearchLog $log,
    ) {}

    /**
     * As the shopper types — never logged (amendment 5(e)).
     *
     * @return list<ProductCard>
     *
     * @throws InvalidCatalogAttribute
     */
    public function suggest(StoreId $store, string $locale, string $typed, int $limit = self::SUGGESTIONS): array
    {
        $locale = ShopLocale::of($locale);
        $terms = $this->terms($typed);

        return $terms === null ? [] : $this->reader->search($store->value, $locale, $terms, min(max($limit, 1), self::SUGGESTIONS))->cards;
    }

    /**
     * Submitted — Enter, or the results page — and logged with how many it found (§1.11). Words that
     * hold no letter or digit search nothing, and nothing is logged.
     *
     * @throws InvalidCatalogAttribute
     */
    public function results(StoreId $store, string $locale, string $typed, int $limit = self::RESULTS): SearchResults
    {
        $locale = ShopLocale::of($locale);
        $terms = $this->terms($typed);

        if ($terms === null) {
            return new SearchResults([], 0);
        }

        $found = $this->reader->search($store->value, $locale, $terms, min(max($limit, 1), self::RESULTS_MAX));
        $this->log->record($store->value, $locale, $terms->text, $found->total);

        return $found;
    }

    private function terms(string $typed): ?SearchTerms
    {
        $terms = SearchTerms::of($typed);

        return $terms->isEmpty() ? null : $terms->withPairs($this->reader->wordPairs($terms->words));
    }
}
