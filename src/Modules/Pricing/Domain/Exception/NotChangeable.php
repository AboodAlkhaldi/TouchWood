<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Changing a sale or discount that has ended, or removing one that is running (pricing.md §4.1, §7):
 * a scheduled one changes in full, a running one only its end, an ended one never.
 */
final class NotChangeable extends PricingError
{
    public function __construct()
    {
        parent::__construct('It can no longer be changed this way.');
    }

    public function type(): string
    {
        return 'pricing.not_changeable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
