<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An end not after the start, or a running sale's or discount's end set before now (pricing.md §1.3,
 * §4.1, §7).
 */
final class WindowInvalid extends PricingError
{
    public function __construct()
    {
        parent::__construct("The end must be after the start; a running one's end cannot be set before now.");
    }

    public function type(): string
    {
        return 'pricing.window_invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
