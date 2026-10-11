<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Service;

use Modules\Inventory\Domain\ValueObject\StockRow;

/**
 * What a size's stock means in a store (inventory.md §1.1, §1.2, §1.3), in one place for `stock()` and
 * for what is pushed into Catalog. Pure. A size with no row counts as 0 (§9.2 #6).
 */
final class StockFigures
{
    /** The pieces that can be ordered or given now: in stock - plus a wired store's returned extra - less held. */
    public static function free(?StockRow $row, bool $wired): int
    {
        return $row === null ? 0 : max(0, $row->inStock + ($wired ? $row->returnedExtra : 0) - $row->held);
    }

    /** Whether its stock limits ordering: always with no provider; in a wired store a stock-dependent size only. */
    public static function counts(?StockRow $row, bool $wired): bool
    {
        return ! $wired || ($row !== null && $row->stockDependent);
    }

    /** Whether it can be ordered now, as far as stock goes (§1.3; Catalog decides the rest). */
    public static function orderable(?StockRow $row, bool $wired): bool
    {
        return ! self::counts($row, $wired) || self::free($row, $wired) > 0;
    }

    /**
     * "Last pieces" (§1.2): a size that counts on stock, with 1 or more free and at most its threshold -
     * with no provider only when staff switched it on for the product; in a wired store by itself.
     */
    public static function endingSoon(?StockRow $row, bool $wired, int $defaultThreshold, bool $productSwitchedOn): bool
    {
        $free = self::free($row, $wired);

        return $row !== null && self::counts($row, $wired) && $free >= 1 && $free <= ($row->threshold ?? $defaultThreshold)
            && ($wired || $productSwitchedOn);
    }
}
