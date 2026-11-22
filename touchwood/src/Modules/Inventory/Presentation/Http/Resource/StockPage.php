<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **Stock** (inventory.md §4.3 I1): the store's sizes and their figures, 50 at a time with the total, the
 * search and the filter applied.
 */
#[TypeScript]
final class StockPage extends Data
{
    /**
     * @param  list<StockRowData>  $rows
     */
    public function __construct(
        public InventoryFrameData $storeFrame,
        public string $storeTimezone,
        public string $search,
        public string $filter,
        public array $rows,
        public ?string $next,
        public int $total,
    ) {}
}
