<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Closure;
use Modules\Catalog\Domain\Exception\CatalogError;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\ValueObject\ListPosition;

/**
 * An order sent from a list's screen (catalog.md amendment 16(d), P29), as `RankCategories` takes a
 * store's menu: **the ids in their new order**. Those sent come first, in the order sent; **every
 * other keeps its order, after them** — so two people ordering at once lose nothing, Move to Top
 * sends one id, and Move to Bottom every other one first. The positions are then written again
 * 1, 2, 3 …, so they never run past the cap (amendment 3(m)).
 */
final class ListOrder
{
    /**
     * The ids as they are compared: each once. An id sent twice says two places for one item, and
     * is refused rather than guessed at.
     *
     * @param  list<string>  $ids
     * @return list<string>
     *
     * @throws InvalidCatalogAttribute
     */
    public static function sent(array $ids): array
    {
        if (count($ids) > ListPosition::MAX) {
            throw new InvalidCatalogAttribute('ids', 'at most '.ListPosition::MAX.' items at once');
        }

        $sent = array_map(static fn (string $id): string => strtolower(trim($id)), $ids);

        if (count(array_unique($sent)) !== count($sent)) {
            throw new InvalidCatalogAttribute('ids', 'each item once');
        }

        return $sent;
    }

    /**
     * What the order changes: every item whose position moves, from what it was to what it is. An
     * order that leaves every item where it is changes nothing.
     *
     * @param  array<string, int>  $current  each item's id => its position, in the list's order now
     * @param  list<string>  $sent  the ids sent, from sent()
     * @param  Closure(string): CatalogError  $notFound  the refusal for an id not in the list
     * @return array<string, array{int, int}> an item's id => [its position before, its position now]
     *
     * @throws CatalogError|InvalidCatalogAttribute
     */
    public static function changes(array $current, array $sent, Closure $notFound): array
    {
        foreach ($sent as $id) {
            if (! array_key_exists($id, $current)) {
                throw $notFound($id);
            }
        }

        if (count($current) > ListPosition::MAX) {
            throw new InvalidCatalogAttribute('ids', 'at most '.ListPosition::MAX.' items');
        }

        $isSent = array_flip($sent);
        $rest = array_values(array_filter(array_keys($current), static fn (string $id): bool => ! isset($isSent[$id])));
        $changes = [];

        foreach ([...$sent, ...$rest] as $index => $id) {
            if ($current[$id] !== $index + 1) {
                $changes[$id] = [$current[$id], $index + 1];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, array{int, int}>  $changes  from changes()
     * @return array<string, int> an item's id => its new position, to write
     */
    public static function positions(array $changes): array
    {
        return array_map(static fn (array $change): int => $change[1], $changes);
    }
}
