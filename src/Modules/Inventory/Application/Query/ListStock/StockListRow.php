<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ListStock;

use Modules\Inventory\Application\Query\StockViews\SizeView;
use Modules\Inventory\Application\Query\StockViews\StockFiguresView;

/**
 * One row of the Stock screen (inventory.md §4.3.3 I1): the size - its names, photo, values, code, and
 * whether it is archived or switched on - and its figures in the store, with its one badge.
 */
final readonly class StockListRow
{
    public function __construct(
        public SizeView $size,
        public StockFiguresView $figures,
    ) {}
}
