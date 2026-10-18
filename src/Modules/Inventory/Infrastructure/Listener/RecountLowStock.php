<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Listener;

use Modules\Inventory\Application\Stock\LowStockRecounts;

/**
 * A size or a product archived or restored leaves the Low Stock list, or comes back to it, in any store
 * (owner, 2026-10-13): every store's count is counted again, after Catalog's change commits.
 */
final readonly class RecountLowStock
{
    public function __construct(
        private LowStockRecounts $recounts,
    ) {}

    public function handle(): void
    {
        $this->recounts->queueAll();
    }
}
