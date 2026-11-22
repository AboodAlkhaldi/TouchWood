<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

/**
 * Every address a page, post, project or blog category ever had (content.md §1.1 rule 15, §5:
 * `content.slug_history`), its current ones included: one address names one item, now or before, per store, kind
 * and language. Kinds are `PAGE`, `POST`, `PROJECT`, `BLOG_CATEGORY` (and `CAMPAIGN`, plan PR 6; `POLICY`, build 4: kept
 * in each store the policy serves with it).
 */
interface SlugHistory
{
    /** The item holding the address, now or before, or null when nobody ever did. */
    public function holder(string $storeId, string $kind, string $locale, string $slug): ?string;

    /** Keeps the address as the item's; one it held before stays held. */
    public function record(string $storeId, string $kind, string $itemId, string $locale, string $slug): void;

    /**
     * Keeps the address as the item's, as `record` does, except that **another item's row is refused by the unique
     * index** rather than skipped: the database's last line under an address the code checked first (`holder`). For
     * policies (build 4, owner 2026-11-20: addresses unique per store, the index the last line).
     */
    public function claim(string $storeId, string $kind, string $itemId, string $locale, string $slug): void;

    /**
     * Hands the item's addresses in these stores, now and before, to another item: a policy split, whose stores move
     * to a new policy, so its old addresses there forward to the new one (owner, 2026-11-20).
     *
     * @param  list<string>  $storeIds
     */
    public function handOver(string $kind, string $fromItemId, string $toItemId, array $storeIds): void;

    /**
     * Gives one address in one store to another item: a policy taking the address of one switched off there (the
     * builder's pick of 2026-11-21, for the owner to confirm), whose old text stays readable by its own version link.
     */
    public function takeOver(string $storeId, string $kind, string $locale, string $slug, string $toItemId): void;

    /**
     * Frees the item's addresses in these stores: a store taken off a policy is left without it, its addresses free.
     *
     * @param  list<string>  $storeIds
     */
    public function forgetIn(string $kind, string $itemId, array $storeIds): void;

    /** Frees every address the item held: a deleted item's addresses are free again (§1.1 rule 11). */
    public function forget(string $kind, string $itemId): void;

    /**
     * @return list<string> every address the item ever held, in either language
     */
    public function slugsOf(string $kind, string $itemId): array;
}
