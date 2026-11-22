<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\LowStock;

/**
 * A page of the Low Stock list: its rows, the cursor of the next page (none on the last) and how many
 * sizes are low in all - "Showing 50 of 312", and the subtitle's "12 low in" the store.
 */
final readonly class LowStockList
{
    /**
     * @param  list<LowStockRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
