<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

/**
 * Top-level URL segments that can never be a store code — "admin", "api", "webhooks" (owner's
 * decision, 2026-09-18). A store named after one of them would be created and then answer 404
 * forever, and the `{store}` route would swallow the module's own URLs.
 *
 * Reserve in your module's service provider register(): Platform builds the `{store}` route
 * pattern from this list at boot and refuses reservations after that. Several modules may reserve
 * the same segment (the admin panel holds pages from many modules).
 */
interface ReservedPaths
{
    /**
     * @param  string  $module  the reserving module, e.g. "access"
     * @param  string  ...$segments  lowercase top-level segments, e.g. "admin", "webhooks"
     *
     * @throws \LogicException after boot, or for a malformed segment
     */
    public function reserve(string $module, string ...$segments): void;
}
