<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A low-stock threshold below 0 or above 100,000 (inventory.md §1.2, §7; owner, 2026-10-10).
 */
final class ThresholdInvalid extends InventoryError
{
    public function __construct()
    {
        parent::__construct('A threshold must be from 0 to 100,000.');
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
