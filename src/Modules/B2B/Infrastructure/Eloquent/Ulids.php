<?php

declare(strict_types=1);

namespace Modules\B2B\Infrastructure\Eloquent;

/**
 * Ids arrive from requests: anything that is not a ULID matches no row, so it is answered as "not
 * found" without asking the database.
 *
 * Access keeps the same ten lines for itself; a module may not reach into another's
 * Infrastructure, and the Shared kernel takes a class only when three modules need it (handoff
 * §4.5). B2B is the second.
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
