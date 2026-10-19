<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ListStock;

use Modules\Inventory\Application\Query\StockViews\SizeView;
use Modules\Inventory\Application\Query\StockViews\StockFiguresView;

/**
 * One row of the Stock screen (inventory.md §4.3.3 I1): the size - its names, photo, values, code, and
 * whether it is archived or switched on - and its figures in the store, with its one badge; and whether its
 * product shows "last pieces" there (none in a wired store) - the row's Show / Hide Last Pieces (stage 5's
 * screens step 5).
 */
final readonly class StockListRow
{
    public function __construct(
        public SizeView $size,
        public StockFiguresView $figures,
        public ?bool $lastPieces = null,
    ) {}
}
