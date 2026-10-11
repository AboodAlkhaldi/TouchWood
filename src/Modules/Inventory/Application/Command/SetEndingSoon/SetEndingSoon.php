<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetEndingSoon;

/**
 * "Last pieces" on or off for a product in a store with no provider (inventory.md §1.2, §3).
 */
final readonly class SetEndingSoon
{
    public function __construct(
        public string $storeId,
        public string $productId,
        public bool $on,
    ) {}
}
