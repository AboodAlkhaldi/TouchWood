<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value that is not one (inventory.md §7): a store, a size or a product that does not exist - an
 * unknown store is invalid input, "since such a store actually doesn't exist" (owner, 2026-10-10) - an
 * archived size or a size of an archived product (read-only until restored, owner, 2026-10-11), a
 * direction or a reason a hand change does not take, a note too long. Named by its field, as
 * Catalog's invalid attribute is.
 */
final class InvalidInventoryInput extends InventoryError
{
    public function __construct(public readonly string $attribute)
    {
        parent::__construct("Invalid {$attribute}.");
    }

    public function type(): string
    {
        return 'inventory.invalid_inventory_input';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute];
    }
}
