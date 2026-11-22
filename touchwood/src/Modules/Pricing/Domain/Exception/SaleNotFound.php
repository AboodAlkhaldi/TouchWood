<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A sale that does not exist in that store (pricing.md §7).
 */
final class SaleNotFound extends PricingError
{
    public function __construct()
    {
        parent::__construct('That sale does not exist.');
    }

    public function type(): string
    {
        return 'pricing.sale_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
