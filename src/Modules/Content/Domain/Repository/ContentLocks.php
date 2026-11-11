<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

/**
 * Per-store locks for the checks no row can hold (content.md §1.1 rule 12): two pop-ups scheduled over each other,
 * two items taking one address. Held until the transaction ends. **One order**: a store's locks first, then its rows
 * (lesson 37); of a store's locks, a transaction taking two takes them in this order - home, banners, pop-ups, themes,
 * blog categories, addresses (`SLUGS`) - and the frame's own (`DatabaseFrameItems`) is taken alone. A theme over several
 * stores takes their theme locks in store-id order, before its own row.
 */
interface ContentLocks
{
    public const string POPUPS = 'popups';

    /** A store's themes: one live at a time (§1.8). A theme over several stores takes theirs in store-id order. */
    public const string THEMES = 'themes';

    public const string SLUGS = 'slugs';

    /** A store's list of home sections: Add and Order. */
    public const string HOME = 'home';

    /** A store's banners of one placement: a new one's place, and Order. */
    public const string BANNERS = 'banners';

    /** A store's blog categories: a new one's place, and Order. */
    public const string BLOG_CATEGORIES = 'blog_categories';

    /** The redirects, which belong to no store (taken with `ALL_STORES`): a new old address, a sheet applied. */
    public const string REDIRECTS = 'redirects';

    /** The store name a lock of something that belongs to no store is taken under. */
    public const string ALL_STORES = '*';

    public function lock(string $storeId, string $topic): void;
}
