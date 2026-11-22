<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A second hold for an order (inventory.md §1.4, §2.1, §7): one hold per order; an edit moves it.
 */
final class AlreadyHeld extends InventoryError
{
    public function __construct()
    {
        parent::__construct('This order already holds stock.');
    }

    public function type(): string
    {
        return 'inventory.already_held';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
