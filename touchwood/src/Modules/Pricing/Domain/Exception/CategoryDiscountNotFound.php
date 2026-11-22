<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A category discount that does not exist in that store (pricing.md §7).
 */
final class CategoryDiscountNotFound extends PricingError
{
    public function __construct()
    {
        parent::__construct('That category discount does not exist.');
    }

    public function type(): string
    {
        return 'pricing.category_discount_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
