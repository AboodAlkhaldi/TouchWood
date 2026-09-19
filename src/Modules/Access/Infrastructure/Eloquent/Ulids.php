<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

/**
 * Ids arrive from requests: anything that is not a ULID matches no row, so it is answered as "not
 * found" without asking the database.
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
