<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\LowStock;

use Modules\Inventory\Application\Query\StockViews\SizeView;
use Modules\Inventory\Application\Query\StockViews\StockFiguresView;

/**
 * One low size (inventory.md §4.3.3 I2): the size - its product's names (a draft may have its Arabic name
 * only), photo, values and code, for staff, never a shopper - and its figures: available with its badge,
 * the threshold own or the store's default, and what the row's Add or Remove dialog says before saving.
 */
final readonly class LowStockRow
{
    public function __construct(
        public SizeView $size,
        public StockFiguresView $figures,
    ) {}
}
