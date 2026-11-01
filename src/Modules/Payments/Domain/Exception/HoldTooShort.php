<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A payment timer changed so the hold limit is no longer longer than the window and the wait together
 * (payments.md §1.7; gap 8.3 (a)).
 */
final class HoldTooShort extends PaymentsError
{
    public function __construct(public readonly int $window, public readonly int $wait, public readonly int $hold)
    {
        parent::__construct("The hold limit ({$hold} minutes) must be longer than the window and the wait together ({$window} + {$wait}).");
    }

    public function type(): string
    {
        return 'payments.hold_too_short';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['window' => $this->window, 'wait' => $this->wait, 'hold' => $this->hold];
    }
}
