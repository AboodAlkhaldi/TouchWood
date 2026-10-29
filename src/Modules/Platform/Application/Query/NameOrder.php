<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query;

use Closure;
use Collator;

/**
 * Alphabetical in the page's language, as that language sorts (stage 7 step 1, Q7): the place
 * pickers and the places pages.
 */
final class NameOrder
{
    /**
     * @template T
     *
     * @param  list<T>  $items
     * @param  Closure(T): string  $name  each item's name in the language
     * @return list<T>
     */
    public static function sort(array $items, string $locale, Closure $name): array
    {
        $collator = Collator::create($locale);
        usort($items, static fn (mixed $a, mixed $b): int => $collator instanceof Collator
            ? (int) $collator->compare($name($a), $name($b))
            : strcmp($name($a), $name($b)));

        return $items;
    }
}
