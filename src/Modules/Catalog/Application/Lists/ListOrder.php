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
     * What the order changes: every item whose position number changes, from what it was to what it
     * is, and whether **its place in the list** changed with it. The two differ for a list never
     * ordered since its places were typed — 10, 20, 30 — whose items are numbered again 1, 2, 3
     * where they stand: written, but not moved. An order that leaves every item where it is, already
     * numbered 1, 2, 3, changes nothing.
     *
     * @param  array<string, int>  $current  each item's id => its position, in the list's order now
     * @param  list<string>  $sent  the ids sent, from sent()
     * @param  Closure(string): CatalogError  $notFound  the refusal for an id not in the list
     * @return array<string, array{int, int, bool}> an item's id => [its position before, its position now, whether its place changed]
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
        $was = array_flip(array_keys($current));
        $rest = array_values(array_filter(array_keys($current), static fn (string $id): bool => ! isset($isSent[$id])));
        $changes = [];

        foreach ([...$sent, ...$rest] as $index => $id) {
            // Its place can change while its number does not, out of two typed alike: still moved.
            if ($current[$id] !== $index + 1 || $was[$id] !== $index) {
                $changes[$id] = [$current[$id], $index + 1, $was[$id] !== $index];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, array{int, int, bool}>  $changes  from changes()
     * @return array<string, int> an item's id => its new position, to write: those whose number changes
     */
    public static function positions(array $changes): array
    {
        $positions = [];

        foreach ($changes as $id => [$before, $now]) {
            if ($before !== $now) {
                $positions[$id] = $now;
            }
        }

        return $positions;
    }

    /**
     * The items an order moved, for the audit log: each one whose place in the list changed, from
     * its position to its position — not one only numbered again where it stands.
     *
     * @param  array<string, array{int, int, bool}>  $changes  from changes()
     * @return array<string, array{int, int}> an item's id => [its position before, its position now]
     */
    public static function moved(array $changes): array
    {
        $moved = [];

        foreach ($changes as $id => [$before, $now, $placeChanged]) {
            if ($placeChanged) {
                $moved[$id] = [$before, $now];
            }
        }

        return $moved;
    }
}
