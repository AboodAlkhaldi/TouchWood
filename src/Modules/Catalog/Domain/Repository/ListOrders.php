<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;

/**
 * The order of Catalog's ordered lists (catalog.md §5.3, amendment 16(d), P29): the brands, the
 * labels, the warranties, the attributes and each attribute's values — every one set by dragging,
 * a new item last. A position is a sort key (`ListPosition`): the list is read by position, then by
 * its English name, then by id, as the screens show it.
 *
 * Read and written under the list's lock (`ListLocks`), which the caller already holds, so the
 * order a change reads is the order it writes over.
 */
interface ListOrders
{
    public const string BRANDS = 'brands';

    public const string LABELS = 'labels';

    public const string WARRANTIES = 'warranties';

    public const string ATTRIBUTES = 'attributes';

    /** One attribute's values: always named with the attribute's id. */
    public const string VALUES = 'values';

    /**
     * @return array<string, int> each item's id => its position, in the list's order
     */
    public function positions(string $list, ?string $attributeId = null): array;

    /**
     * Where a new item goes (P29): the position after the last. Positions are written 1, 2, 3 … by
     * every order, so this stays far below the cap; should it reach it all the same — a list never
     * ordered since — the list is numbered again 1, 2, 3 … in its order first, each item keeping its
     * place among the others.
     *
     * @throws InvalidCatalogAttribute when the list already holds as many items as positions go
     */
    public function afterLast(string $list, ?string $attributeId = null): int;

    /**
     * @param  array<string, int>  $positions  an item's id => its new position
     */
    public function write(string $list, array $positions): void;
}
