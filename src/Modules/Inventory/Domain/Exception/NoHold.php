<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Editing or returning for an order that holds nothing (inventory.md §2.1, §7).
 */
final class NoHold extends InventoryError
{
    public function __construct()
    {
        parent::__construct('This order holds no stock.');
    }

    public function type(): string
    {
        return 'inventory.no_hold';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
