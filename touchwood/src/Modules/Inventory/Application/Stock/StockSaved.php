<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

/**
 * **What a stocktake or a hand change answers** (stage 5's screens step 4; inventory.md §4.3.1 "Toasts"):
 * the sizes it changed that orders now hold more of than there is - none when every order still fits -
 * so the screen shows its amber toast after the save.
 */
final readonly class StockSaved
{
    /**
     * @param  list<ShortSize>  $short  in size-id order
     */
    public function __construct(
        public array $short = [],
    ) {}
}
