<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A removal larger than what is in stock (inventory.md §1.5, §7): stock may fall below what is held -
 * those orders are flagged - but never below 0.
 */
final class StockBelowZero extends InventoryError
{
    public function __construct()
    {
        parent::__construct('That would take the stock below 0.');
    }

    public function type(): string
    {
        return 'inventory.stock_below_zero';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
