<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Shipping more of a line than it holds (inventory.md §1.4, §7).
 */
final class ShipMoreThanHeld extends InventoryError
{
    public function __construct()
    {
        parent::__construct('That ships more of a line than it holds.');
    }

    public function type(): string
    {
        return 'inventory.ship_more_than_held';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
