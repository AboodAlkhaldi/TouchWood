<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Modules\Content\Domain\Exception\InvalidContentInput;

/**
 * A dragged order (content.md §4.2: `SortableList`, Move to Top and Move to Bottom): the whole order the person
 * sees is sent, never "just this id" (lesson 191), so it must name every item of the list, each once.
 */
final class Ordering
{
    /**
     * @param  list<string>  $sent
     * @param  list<string>  $present  the list's items now
     * @return list<string> the order, lower-cased
     *
     * @throws InvalidContentInput
     */
    public static function of(string $attribute, array $sent, array $present): array
    {
        $sent = array_map(static fn (string $id): string => strtolower(trim($id)), $sent);
        $sorted = $sent;
        $expected = $present;
        sort($sorted);
        sort($expected);

        if ($sorted !== $expected) {
            throw new InvalidContentInput($attribute, InvalidContentInput::ORDER);
        }

        return $sent;
    }
}
