<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

/**
 * Every address a page, post, project or blog category ever had (content.md §1.1 rule 15, §5:
 * `content.slug_history`), its current ones included: one address names one item, now or before, per store, kind
 * and language. Kinds are `PAGE`, `POST`, `PROJECT`, `BLOG_CATEGORY` (and `CAMPAIGN`, plan PR 6).
 */
interface SlugHistory
{
    /** The item holding the address, now or before, or null when nobody ever did. */
    public function holder(string $storeId, string $kind, string $locale, string $slug): ?string;

    /** Keeps the address as the item's; one it held before stays held. */
    public function record(string $storeId, string $kind, string $itemId, string $locale, string $slug): void;

    /** Frees every address the item held: a deleted item's addresses are free again (§1.1 rule 11). */
    public function forget(string $kind, string $itemId): void;

    /**
     * @return list<string> every address the item ever held, in either language
     */
    public function slugsOf(string $kind, string $itemId): array;
}
