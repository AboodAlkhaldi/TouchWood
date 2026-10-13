<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A low-stock threshold below 0 (inventory.md §1.2, §7).
 */
final class ThresholdInvalid extends InventoryError
{
    public function __construct()
    {
        parent::__construct('A threshold must be 0 or more.');
    }

    public function type(): string
    {
        return 'inventory.threshold_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
