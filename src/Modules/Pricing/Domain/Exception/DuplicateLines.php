<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Two lines of the same variant and sale mode handed to `prices()`, or two added lines of one in
 * `pricesForEdit()` (pricing.md §2.1): refused rather than guessed at - the caller merges them first.
 */
final class DuplicateLines extends PricingError
{
    public function __construct()
    {
        parent::__construct('Each variant and sale mode may appear once.');
    }

    public function type(): string
    {
        return 'pricing.duplicate_lines';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
