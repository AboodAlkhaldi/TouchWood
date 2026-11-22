<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Service;

use Modules\Inventory\Domain\ValueObject\StockBadge;
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

    /**
     * Whether a wired store's switch is on for the size: **on by default** (owner, 2026-10-28; §9.2 #24),
     * so a size with no row is on, and only a row staff switched off says off.
     */
    public static function dependent(?StockRow $row): bool
    {
        return $row === null || $row->stockDependent;
    }

    /** Whether its stock limits ordering: always with no provider; in a wired store unless staff switched the size off. */
    public static function counts(?StockRow $row, bool $wired): bool
    {
        return ! $wired || self::dependent($row);
    }

    /** Whether it can be ordered now, as far as stock goes (§1.3; Catalog decides the rest). */
    public static function orderable(?StockRow $row, bool $wired): bool
    {
        return ! self::counts($row, $wired) || self::free($row, $wired) > 0;
    }

    /**
     * How many pieces orders hold beyond what there is (§4.3.1, Q18): held above in stock - in a wired
     * store above in stock plus its returned extra (§1.9) - or 0.
     */
    public static function shortBy(?StockRow $row, bool $wired): int
    {
        return $row === null ? 0 : max(0, $row->held - $row->inStock - ($wired ? $row->returnedExtra : 0));
    }

    /**
     * **The one badge a row shows** (§4.3.1, Q17, Q18), the first that applies - Short, Out of Stock,
     * Orderable (a wired ordinary size at 0, §1.3), Low - or none.
     */
    public static function badge(?StockRow $row, bool $wired, int $defaultThreshold): ?StockBadge
    {
        $free = self::free($row, $wired);

        return match (true) {
            self::shortBy($row, $wired) > 0 => StockBadge::Short,
            $free === 0 && self::counts($row, $wired) => StockBadge::OutOfStock,
            $free === 0 => StockBadge::Orderable,
            $free <= ($row->threshold ?? $defaultThreshold) => StockBadge::Low,
            default => null,
        };
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
