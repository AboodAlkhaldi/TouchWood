<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Totals asked with an amount in another currency, a negative amount, or discounts larger than the
 * net subtotal (pricing.md §1.7, §2.1).
 */
final class AmountsInvalid extends PricingError
{
    public function __construct()
    {
        parent::__construct('The amounts do not fit these prices.');
    }

    public function type(): string
    {
        return 'pricing.amounts_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
