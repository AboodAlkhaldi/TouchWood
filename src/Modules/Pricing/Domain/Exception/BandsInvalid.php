<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Wholesale bands that do not start at the product's wholesale minimum, or a band that does not start
 * higher and cost less than the one before (pricing.md §1.4, §7).
 */
final class BandsInvalid extends PricingError
{
    public function __construct()
    {
        parent::__construct('The first band starts at the wholesale minimum; each next band starts higher and costs less.');
    }

    public function type(): string
    {
        return 'pricing.bands_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
