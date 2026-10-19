<?php

declare(strict_types=1);

namespace Modules\Inventory\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A size's figures in a store (inventory.md §4.3.1): in stock, held, available, a wired store's returned
 * extra, the threshold that applies and its own, the one badge (`SHORT`, `OUT_OF_STOCK`, `ORDERABLE`,
 * `LOW`) with how many short, whether its stock counts, the provider's last change, and how many orders
 * hold it.
 */
#[TypeScript]
final class StockFiguresData extends Data
{
    public function __construct(
        public int $inStock,
        public int $held,
        public int $available,
        public int $returnedExtra,
        public int $threshold,
        public ?int $ownThreshold,
        public ?string $badge,
        public int $shortBy,
        public bool $countsOnStock,
        public bool $stockDependent,
        public ?string $providerChangedAt,
        public int $holdingOrders,
    ) {}
}
