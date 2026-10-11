<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

use Closure;

/**
 * **The shop's pages, kept** (catalog.md §4.5, P20, P21): the menu per store, language and viewer,
 * and a product's page per store, language and viewer, in the never-stale cache (CONVENTIONS,
 * `VersionedCache`) — one version for all of them, replaced **inside every change that alters them**:
 * each of Catalog's changes takes a list's lock, and taking one replaces the version first
 * (DatabaseListLocks), as each fact pushed in does (DatabaseListingFacts).
 */
interface ShopPagesCache
{
    /**
     * @template T
     *
     * @param  string  $part  which page: its store, language, viewer and own key, never longer than
     *                        the cache's key allows (a slug is never part of it)
     * @param  Closure(): T  $load
     * @return T
     */
    public function remember(string $part, Closure $load): mixed;

    /**
     * Inside the change's transaction: the new version is seen exactly when the change is.
     */
    public function invalidate(): void;

    /**
     * Removes what old versions kept, once out of date: nothing reads it again.
     *
     * @return int how many were removed
     */
    public function prune(): int;
}
