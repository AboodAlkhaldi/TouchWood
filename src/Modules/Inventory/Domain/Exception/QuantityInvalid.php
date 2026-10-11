<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A quantity below 1, or a stocktake below 0 (inventory.md §7).
 */
final class QuantityInvalid extends InventoryError
{
    public function __construct()
    {
        parent::__construct('A quantity must be at least 1, and a count at least 0.');
    }

    public function type(): string
    {
        return 'inventory.quantity_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
