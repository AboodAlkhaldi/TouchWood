<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Totals asked for prices that hold a line with no price (pricing.md §1.7, §2.1): no price, no sale.
 */
final class LinesWithoutPrice extends PricingError
{
    public function __construct()
    {
        parent::__construct('Some lines have no price in this store.');
    }

    public function type(): string
    {
        return 'pricing.lines_without_price';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
