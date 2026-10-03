<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

/**
 * Ids arrive from requests: anything that is not a ULID matches no row, so it is answered as "not
 * found" without asking the database.
 *
 * Access and B2B keep the same ten lines for themselves; a module may not reach into another's
 * Infrastructure, and the owner chose a third copy over moving it to the Shared kernel, so Access and
 * B2B stay untouched (catalog.md amendment 1(h), 2026-10-03).
 */
final class Ulids
{
    /** Crockford base32, as Str::ulid() produces. */
    private const string PATTERN = '/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/i';

    public static function valid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }
}
