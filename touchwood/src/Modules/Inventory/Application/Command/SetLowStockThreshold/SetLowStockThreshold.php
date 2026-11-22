<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetLowStockThreshold;

/**
 * Each size's own low-stock threshold in a store (inventory.md §1.2, §1.7, §3): one size or several;
 * null for the store's default.
 */
final readonly class SetLowStockThreshold
{
    /**
     * @param  array<string, int|null>  $thresholds  variant id => its threshold, or null for the store's default
     */
    public function __construct(
        public string $storeId,
        public array $thresholds,
    ) {}
}
