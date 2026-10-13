<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Editing a hold whose line is already shipped or ticked "reduced in the provider" (inventory.md §1.4,
 * §2.1, §7): an order is edited only before it ships.
 */
final class HoldNotEditable extends InventoryError
{
    public function __construct()
    {
        parent::__construct('Part of this order is already shipped or reduced in the provider.');
    }

    public function type(): string
    {
        return 'inventory.hold_not_editable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
