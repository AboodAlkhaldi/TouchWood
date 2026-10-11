<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\DomainError;

/**
 * The base of every error the Inventory module raises (inventory.md §7). Other modules see them only
 * as `DomainError`, by their `inventory.*` type: modules export no error classes (§2.1).
 */
abstract class InventoryError extends DomainError {}
