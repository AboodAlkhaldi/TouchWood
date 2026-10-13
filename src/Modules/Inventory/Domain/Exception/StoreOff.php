<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Changing the stock of a store that is off without the store switch (inventory.md §1.1 rule 6, §3,
 * §7): an off store's stock is set by Super Admins only, or by its file (owner, 2026-10-07).
 */
final class StoreOff extends InventoryError
{
    public function __construct()
    {
        parent::__construct('This store is off: only whoever may switch stores changes its stock.');
    }

    public function type(): string
    {
        return 'inventory.store_off';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
