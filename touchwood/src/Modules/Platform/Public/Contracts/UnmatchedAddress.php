<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Illuminate\Http\RedirectResponse;

/**
 * Answers a read of an address no page of the site answers (content.md §2.3 row L2, platform.md L2):
 * Content's redirect lookup, sending an old site's address on to its new page.
 *
 * Registered with `UnmatchedAddresses`. Asked for a GET (or HEAD) only, and only where the site would
 * otherwise say "not found": an address no route matched (`/flash-deals`, `/product/x`), a single word
 * that looks like a store's code but names no store (`/blog`, `/contact`), a first word that names no store
 * before a language (`/shop/en/x`), and an address under a store that no page answers (`/sa/ar/old-page`;
 * the last two the PR 2 review's pick). Never for an address under a
 * reserved first segment (`/admin/...`, `/build/...`, `/api/...`), and never for a page that exists.
 *
 * **No store context, and no session**: the second place runs inside the shop's session middleware, so an
 * answer must read nothing of the request but the path it is given and write nothing to the session.
 *
 * **An answer never echoes the path into its Location**: it sends the visitor only to an address it holds
 * itself (a stored redirect's new path), never to one built from what was asked, so no address a stranger
 * types can send a browser elsewhere. A path holding a control character or a backslash is never asked
 * about at all.
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
