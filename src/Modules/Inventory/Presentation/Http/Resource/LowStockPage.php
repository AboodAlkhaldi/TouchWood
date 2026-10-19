<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **Low Stock** (inventory.md §4.3 I2): the sizes at or below their threshold, the fewest left first, 50
 * at a time with the total.
 */
#[TypeScript]
final class LowStockPage extends Data
{
    /**
     * @param  list<StockRowData>  $rows
     */
    public function __construct(
        public InventoryFrameData $storeFrame,
        public string $storeTimezone,
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
