<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Shop;

use DateTimeImmutable;
use Modules\Content\Domain\Model\DocumentKind;

/**
 * What the shop's own Content pages read (content.md §4.5, H2-H7): a published page, post or project by its address,
 * the blog's and the projects' lists, the blog's categories, and a campaign's run by its address. **Only what is
 * published answers**: a draft reads as an address nobody holds, so its page is the shop's 404 (§1.1 rule 15). Reads
 * never lock; every id comes back lower-case.
 */
interface ShopDocuments
{
    /**
     * The published item of this store whose current address in this language is this slug, as its page shows it:
     * its words in that language, its address in the other, its body's blocks and the files they name, and what its kind
     * adds (a post's summary, cover and category; a project's city and photos; their products, in their order).
     *
     * @param  'ar'|'en'  $locale
     * @return array{id: string, title: string, slug: string, otherSlug: string|null, body: array<string, mixed>, seoTitle: string|null, seoDescription: string|null, shareMediaId: string|null, publishedAt: DateTimeImmutable|null, summary: string|null, coverMediaId: string|null, coverAlt: string|null, city: string|null, category: array{name: string, slug: string}|null, images: list<array{ref: int, mediaId: string, alt: string|null}>, products: list<string>, photos: list<string>}|null
     */
    public function published(DocumentKind $kind, string $storeId, string $locale, string $slug): ?array;

    /**
     * The item's current address in this language (`ar`, else `en`) while it is published; null for a draft or an
     * item gone.
     */
    public function currentSlug(DocumentKind $kind, string $itemId, string $locale): ?string;

    /**
     * The store's published posts, newest first (H3), in one category or all, from where the last page ended.
     *
     * @param  'ar'|'en'  $locale
     * @param  array{0: string, 1: string}|null  $after  the last post shown: its publish moment (ISO 8601) and id
     * @return list<array{id: string, title: string, slug: string, summary: string|null, coverMediaId: string|null, coverAlt: string|null, publishedAt: DateTimeImmutable, category: string|null}>
     */
    public function posts(string $storeId, string $locale, ?string $categoryId, ?array $after, int $limit): array;

    /**
     * The store's published projects, newest first (H5), from where the last page ended.
     *
     * @param  'ar'|'en'  $locale
     * @param  array{0: string, 1: string}|null  $after
     * @return list<array{id: string, title: string, slug: string, city: string|null, photoMediaId: string|null, publishedAt: DateTimeImmutable}>
     */
    public function projects(string $storeId, string $locale, ?array $after, int $limit): array;

    /**
     * The store's blog categories that hold a published post, in their order (H3's chips).
     *
     * @return list<array{id: string, name_ar: string, name_en: string, slug_ar: string, slug_en: string}>
     */
    public function blogCategories(string $storeId): array;

    /** Whether the store has anything of this kind published (H5: the projects stay hidden until one is). */
    public function anyPublished(DocumentKind $kind, string $storeId): bool;

    /**
     * The store's campaign run whose address is this slug in this language, or the other's (H7), whatever its state.
     *
     * @param  'ar'|'en'  $locale
     * @return array{id: string, state: string, endsAt: DateTimeImmutable, name: string, slug: string|null, otherSlug: string|null, inOtherLanguage: bool}|null
     */
    public function run(string $storeId, string $locale, string $slug): ?array;
}
