<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A hand change or "ending soon" in a store wired to a provider (inventory.md §1.5, §3, §7): the
 * provider's stock is read, never changed here.
 */
final class ProviderOwnsStock extends InventoryError
{
    public function __construct()
    {
        parent::__construct("This store's stock comes from its provider.");
    }

    public function type(): string
    {
        return 'inventory.provider_owns_stock';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
