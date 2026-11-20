<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Shop;

/**
 * The rows the shop's cached Content is made from (content.md §1.4, §1.5), read only when the cache is cold. Moments
 * are ISO 8601 strings in UTC, so what is kept is plain data; every item scheduled and not ended is read, its start
 * and end with it, for the reader to filter by the clock.
 */
interface ShopReads
{
    /**
     * The bar, the footer, the pop-ups, the themes (each with its parts' words and images), the smart bars and the
     * listing banners (content.md §1.5, §1.6, §1.8, §1.9), the store's SEO defaults (§1.14) for Content's own pages, and
     * its published policies for the footer's bottom line (flags-2026-11.md S3.5, F19; `policies()`).
     *
     * @return array{bar: array<string, mixed>|null, footer: array<string, mixed>|null, seo?: array<string, mixed>|null, popups: list<array<string, mixed>>, themes: list<array<string, mixed>>, smartBars: list<array<string, mixed>>, listings: list<array<string, mixed>>, policies?: list<array<string, mixed>>}
     */
    public function frame(string $storeId): array;

    /**
     * The store's published policies (flags-2026-11.md S3), both languages: each with its kind, the number of its text
     * (F14), when that text was published ("Last updated", F15), its title and address in each language - in the kinds'
     * order (S3.5, F19), the Other ones by when they were made. One query; kept in the cache by its readers.
     *
     * @return list<array{id: string, kind: string, version: int, updated: string, title_ar: string, slug_ar: string, title_en: string|null, slug_en: string|null}>
     */
    public function policies(string $storeId): array;

    /**
     * One theme of this store as Preview Theme shows it (content.md W5b), whatever its state and dates, read past the
     * cache: shaped as one of `frame()`'s themes, with no start or end, plus its name. Null when it is not one of this
     * store's.
     *
     * @return array{id: string, slug: string, parts: array<string, array<string, mixed>>, starts: null, ends: null, name_ar: string, name_en: string|null}|null
     */
    public function previewTheme(string $themeId, string $storeId): ?array;

    /**
     * @return array{sections: list<array<string, mixed>>, banners: list<array<string, mixed>>, projects: list<array<string, mixed>>, posts: list<array<string, mixed>>}
     */
    public function home(string $storeId): array;
}
