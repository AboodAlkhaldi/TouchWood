<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * **A size's drawer** (inventory.md §4.3 I1, Q3): its figures and badge, its product's Last Pieces (no
 * provider), its holds.
 */
#[TypeScript]
final class SizeStockData extends Data
{
    public function __construct(
        public StockSizeData $size,
        public StockFiguresData $figures,
        public ?bool $lastPieces,
        public HoldsData $holds,
    ) {}
}
