<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ListStock;

/**
 * A page of the Stock screen: its rows, the cursor of the next page (none on the last) and how many rows
 * the filters find in all ("Showing 50 of 312").
 */
final readonly class StockList
{
    /**
     * @param  list<StockListRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
