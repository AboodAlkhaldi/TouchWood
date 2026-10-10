<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A price, a wholesale band or a fixed amount of 0 or less (pricing.md §1.1 rule 4, §7): a free piece
 * is a gift, which is Promotions'.
 */
final class PriceNotPositive extends PricingError
{
    public function __construct()
    {
        parent::__construct('A price must be above 0.');
    }

    public function type(): string
    {
        return 'pricing.price_not_positive';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
