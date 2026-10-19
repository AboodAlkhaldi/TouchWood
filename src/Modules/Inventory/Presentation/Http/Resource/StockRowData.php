<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row of Stock (I1) or Low Stock (I2): the size, its figures, and whether its product shows "last
 * pieces" (none in a wired store, or on Low Stock).
 */
#[TypeScript]
final class StockRowData extends Data
{
    public function __construct(
        public StockSizeData $size,
        public StockFiguresData $figures,
        public ?bool $lastPieces,
    ) {}
}
