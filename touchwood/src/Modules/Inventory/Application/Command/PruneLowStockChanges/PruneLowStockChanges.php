<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\PruneLowStockChanges;

/**
 * The low-stock change log's nightly removal (inventory.md §1.7, amendment 1): entries older than twelve
 * months go.
 */
final readonly class PruneLowStockChanges {}
