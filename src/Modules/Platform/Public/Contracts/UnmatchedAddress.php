<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Illuminate\Http\RedirectResponse;

/**
 * Answers a read of an address no page of the site answers (content.md §2.3 row L2, platform.md L2):
 * Content's redirect lookup, sending an old site's address on to its new page.
 *
 * Registered with `UnmatchedAddresses`. Asked for a GET (or HEAD) only, and only where the site would
 * otherwise say "not found": an address no route matched (`/flash-deals`, `/product/x`), and a single word
 * that looks like a store's code but names no store (`/blog`, `/contact`). Never for an address under a
 * reserved first segment (`/admin/...`, `/build/...`, `/api/...`), and never for a page that exists.
 *
 * **No store context, and no session**: the second place runs inside the shop's session middleware, so an
 * answer must read nothing of the request but the path it is given and write nothing to the session.
 */
interface UnmatchedAddress
{
    /**
     * @param  string  $path  the address as asked for: decoded (UTF-8, Arabic as letters), with its leading
     *                        slash and any trailing one, without the query string - "/flash-deals",
     *                        "/مدونة/", "/blog"
     * @return RedirectResponse|null where to send the visitor, or null to leave the address not found
     */
    public function answer(string $path): ?RedirectResponse;
}
