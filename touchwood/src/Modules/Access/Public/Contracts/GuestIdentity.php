<?php

declare(strict_types=1);

namespace Modules\Access\Public\Contracts;

/**
 * The guest this browser is (access.md amendment 74, §1.7): the id in the storefront's encrypted,
 * HTTP-only cookie. **Created only when something needs it** — Sales, with a guest's first cart line —
 * never on a page view, so bots create nothing; kept for the year the owner set (2026-09-20). When the
 * guest signs in or registers, Access publishes `GuestBecameCustomer` with this id.
 */
interface GuestIdentity
{
    /** The guest id this request carries, or null when the browser has none yet. */
    public function current(): ?string;

    /**
     * The guest id, created now — its cookie sent with the answer — when the browser has none. On a route
     * of the `web` group, which sends queued cookies. Two first asks at once from one browser (a double
     * click, two tabs) each make an id; the browser keeps the cookie of the answer that came last.
     */
    public function ensure(): string;
}
