<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A category discount on a category that does not exist or is off (pricing.md §1.5, §7).
 */
final class CategoryNotUsable extends PricingError
{
    public function __construct()
    {
        parent::__construct('That category does not exist or is off.');
    }

    public function type(): string
    {
        return 'pricing.category_not_usable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
