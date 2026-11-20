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
     * The published policy serving this store whose current address in this language is this slug (flags-2026-11.md
     * S3.1), as its page shows it: its words in that language, its address in the other, its body's blocks and their
     * files, the number of its published text (F14) and when that text was published ("Last updated", F15). Without
     * `$images` its body's files are not read (empty): a kept version brings its own (`policyVersion`).
     *
     * @param  'ar'|'en'  $locale
     * @return array{id: string, kind: string, title: string, slug: string, otherSlug: string|null, body: array<string, mixed>, seoTitle: string|null, seoDescription: string|null, shareMediaId: string|null, version: int, updatedAt: DateTimeImmutable|null, images: list<array{ref: int, mediaId: string, alt: string|null}>}|null
     */
    public function policy(string $storeId, string $locale, string $slug, bool $images = true): ?array;

    /**
     * When a version of a policy was in force in this store and is no longer (the owner's decisions of 2026-11-20): each
     * period there, in order, from and to, with the addresses it had. Empty when that version was never in force there,
     * or still is.
     *
     * @return list<array{from: DateTimeImmutable, until: DateTimeImmutable, slugAr: string, slugEn: string}>
     */
    public function policyPeriods(string $storeId, string $policyId, int $number): array;

    /**
     * The text last in force in this store at this address in this language and in force no more (the owner's decisions
     * of 2026-11-20: a past version, or a policy switched off there) - of the version and the policy asked, when asked:
     * its policy, kind, number and periods there, as `policyPeriods`. Null when there is none.
     *
     * @param  'ar'|'en'  $locale
     * @return array{id: string, kind: string, number: int, periods: list<array{from: DateTimeImmutable, until: DateTimeImmutable, slugAr: string, slugEn: string}>}|null
     */
    public function pastPolicy(string $storeId, string $locale, string $slug, ?int $number, ?string $policyId): ?array;

    /**
     * One kept text of a policy by its number (S3.2, F14; owner 2026-11-20: reached at `?version=N`), in this language:
     * its title, body and the body's files as they were published, and when. Null when it has no text of that number.
     *
     * @param  'ar'|'en'  $locale
     * @return array{title: string, body: array<string, mixed>, publishedAt: DateTimeImmutable|null, images: list<array{ref: int, mediaId: string, alt: string|null}>}|null
     */
    public function policyVersion(string $policyId, int $number, string $locale): ?array;

    /**
     * A policy's current address in this language while it is published and serves this store; null otherwise.
     */
    public function currentPolicySlug(string $storeId, string $policyId, string $locale): ?string;

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
