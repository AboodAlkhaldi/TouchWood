<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Closure;

/**
 * What the shop reads of Content, kept in the never-stale cache (content.md §1.4, §1.5; handoff §5.4,
 * `VersionedCache`): the frame's part and the home page, per store and language. **One version for all of it**:
 * every Content change that alters what a shopper sees replaces it **inside the change's transaction**, and so does
 * a file's sizes becoming ready (`MediaVariantsReady`).
 *
 * What is kept carries each item's start and end; the reader filters by the clock on each request, so nothing waits
 * for the cache at a start or an end.
 */
interface ContentCache
{
    /**
     * @template T
     *
     * @param  string  $part  `frame:{store}:{locale}`, `home:{store}:{locale}`
     * @param  Closure(): T  $load
     * @return T
     */
    public function remember(string $part, Closure $load): mixed;

    /** Call inside the transaction of the change. */
    public function invalidate(): void;
}
