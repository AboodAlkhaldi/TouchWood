<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetStockFromFile;

/**
 * A store file's stock for one size it switched on (inventory.md §1.8): queued by Inventory's part of
 * the file once Catalog's change commits, run by the system for whoever switched it on. Its reference
 * is made when it is queued, so the job run again - a retry, a redelivery - sets nothing twice.
 */
final readonly class SetStockFromFile
{
    public function __construct(
        public string $storeId,
        public string $variantId,
        public int $stock,
        public string $reference,
    ) {}
}
