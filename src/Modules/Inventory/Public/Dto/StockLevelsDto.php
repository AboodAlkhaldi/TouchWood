<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Dto;

/**
 * **A page of `InventoryApi::stockLevels`** (inventory.md §2.1, amendment 1): the sizes in id order, and the
 * cursor of the page after it (none on the last).
 */
final readonly class StockLevelsDto
{
    /**
     * @param  list<StockLevelDto>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
