<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A percentage off that is not above 0 and below 100 (pricing.md §1.3, §1.5, §7).
 */
final class PercentOutOfRange extends PricingError
{
    public function __construct()
    {
        parent::__construct('A percentage off must be above 0 and below 100.');
    }

    public function type(): string
    {
        return 'pricing.percent_out_of_range';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
