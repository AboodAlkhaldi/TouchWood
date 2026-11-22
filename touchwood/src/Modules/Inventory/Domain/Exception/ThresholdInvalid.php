<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A low-stock threshold below 0 or above 100,000 (inventory.md §1.2, §7; owner, 2026-10-10). Names the
 * sizes it is refused for (`variants`; stage 5's screens, Q2), so the panel marks their rows.
 */
final class ThresholdInvalid extends InventoryError
{
    /**
     * @param  list<string>  $variants  the sizes it is refused for
     */
    public function __construct(public readonly array $variants = [])
    {
        parent::__construct('Threshold must be from 0 to 100,000.');
    }

    public function type(): string
    {
        return 'inventory.threshold_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return $this->variants === [] ? [] : ['variants' => implode(',', $this->variants)];
    }
}
