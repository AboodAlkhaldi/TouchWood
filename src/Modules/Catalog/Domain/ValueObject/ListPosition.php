<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\ValueObject;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * Where something sits in its list — a brand among brands, a value in its attribute, a label on a
 * card, a category among its siblings in one store's menu. Two may share a number; they are then
 * ordered by name, so it is a sort key, not a slot.
 */
final class ListPosition
{
    /** Far more than any list holds, and far below what the column takes. */
    public const int MAX = 10000;

    /**
     * @throws InvalidCatalogAttribute
     */
    public static function check(int $position, string $attribute = 'position'): int
    {
        if ($position < 0 || $position > self::MAX) {
            throw new InvalidCatalogAttribute($attribute, 'between 0 and '.self::MAX);
        }

        return $position;
    }
}
