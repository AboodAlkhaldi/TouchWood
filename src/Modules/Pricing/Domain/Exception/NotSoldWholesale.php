<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Wholesale bands for a size the store does not sell wholesale (pricing.md §1.4, §7) - Catalog's
 * selling modes decide that.
 */
final class NotSoldWholesale extends PricingError
{
    public function __construct()
    {
        parent::__construct('The store does not sell this size wholesale.');
    }

    public function type(): string
    {
        return 'pricing.not_sold_wholesale';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
