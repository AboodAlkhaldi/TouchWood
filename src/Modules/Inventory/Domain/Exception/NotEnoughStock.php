<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A hold one line short (inventory.md §1.4, §2.1, §7): all or nothing, so nothing is held. Which
 * variants were short, and what was available, join its context with the hold itself (step 3).
 */
final class NotEnoughStock extends InventoryError
{
    public function __construct()
    {
        parent::__construct('Not enough stock for every line.');
    }

    public function type(): string
    {
        return 'inventory.not_enough_stock';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
