<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Persistence;

/**
 * Ids arrive from requests: anything that is not a ULID matches no row, so it is answered as "not
 * found" without asking the database.
 *
 * Access, B2B and Catalog each kept these lines for themselves (catalog.md amendment 1(h)); with
 * Loyalty a fourth module needed them, and the owner moved them here, the handoff's rule for anything
 * three or more modules use (handoff §4.5; owner, 2026-10-10).
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
