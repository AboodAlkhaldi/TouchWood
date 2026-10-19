<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ViewStock;

use Modules\Inventory\Application\Query\StockViews\HoldsView;
use Modules\Inventory\Application\Query\StockViews\SizeView;
use Modules\Inventory\Application\Query\StockViews\StockFiguresView;

/**
 * **A size's drawer** (inventory.md §4.3.3 `ViewStock`, I1's drawer): the size, its figures and its one
 * badge, its threshold own or the default, the provider's last change (a wired store; in the figures),
 * **Last Pieces** for its product in a store with no provider (none in a wired store, where the size's
 * own **Counts on Stock** is in the figures), and its holds - how many orders hold how many pieces,
 * bought and gift apart (Q21).
 */
final readonly class SizeStockView
{
    public function __construct(
        public SizeView $size,
        public StockFiguresView $figures,
        public ?bool $lastPieces,
        public HoldsView $holds,
    ) {}
}
