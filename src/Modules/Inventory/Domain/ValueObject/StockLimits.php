<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * The numbers stock is kept within (inventory.md §1.2, §5), in one place.
 */
final class StockLimits
{
    /** The most pieces a size's stock, or one change of it, can hold: the column's limit. */
    public const int MOST_PIECES = 2147483647;

    /** A low-stock threshold's highest, the store's default and each size's own (owner, 2026-10-10). */
    public const int THRESHOLD_MOST = 100000;
}
