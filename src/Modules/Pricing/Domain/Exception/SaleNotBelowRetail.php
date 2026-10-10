<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A sale price not below the size's retail price when it is saved (pricing.md §1.3, §7): otherwise it
 * is not a sale (owner, 2026-10-08).
 */
final class SaleNotBelowRetail extends PricingError
{
    public function __construct()
    {
        parent::__construct('A sale price must be below the retail price.');
    }

    public function type(): string
    {
        return 'pricing.sale_not_below_retail';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
