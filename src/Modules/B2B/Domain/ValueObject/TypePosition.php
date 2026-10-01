<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;

/**
 * Where a type sits on the form (b2b.md §1.3), for both kinds of type. Two types may share a
 * position — they are then ordered by name — so it is a sort key, not a slot.
 */
final class TypePosition
{
    /** Far more than any form will list, and far below what the column holds. */
    public const int MAX = 10000;

    /**
     * @throws InvalidCompanyAttribute
     */
    public static function check(int $position): int
    {
        if ($position < 0 || $position > self::MAX) {
            throw new InvalidCompanyAttribute('position', 'between 0 and '.self::MAX);
        }

        return $position;
    }
}
