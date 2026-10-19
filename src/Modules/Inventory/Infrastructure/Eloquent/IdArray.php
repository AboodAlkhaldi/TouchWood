<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Eloquent;

use Shared\Infrastructure\Persistence\Ulids;

/**
 * Many ids bound as **one PostgreSQL array** (`= ANY(?::text[])`), so a screen's read over every store
 * and size is one query under the parameter limit, as Catalog's searches bind theirs. Only ULIDs pass -
 * letters and digits, nothing to quote - lower-cased as every id is kept; anything else matches no row.
 */
final class IdArray
{
    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function valid(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => strtolower(trim($id)), $ids),
            Ulids::valid(...),
        )));
    }

    /**
     * @param  list<string>  $ids  as `valid()` gives them
     */
    public static function of(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }
}
