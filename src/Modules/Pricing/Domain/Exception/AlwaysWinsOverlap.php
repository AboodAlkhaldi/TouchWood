<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A second "always wins" sale or discount on a size in the same dates (pricing.md §1.3, §7): refused
 * (owner, 2026-10-09).
 */
final class AlwaysWinsOverlap extends PricingError
{
    public function __construct()
    {
        parent::__construct('Another sale or discount that always wins already covers these sizes in these dates.');
    }

    public function type(): string
    {
        return 'pricing.always_wins_overlap';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
