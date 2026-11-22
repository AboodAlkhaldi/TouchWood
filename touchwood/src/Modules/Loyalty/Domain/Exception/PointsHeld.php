<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A deduction by hand that would take points held for an online payment still in progress (loyalty.md
 * §9.6; owner, 2026-10-29, gap 7.2 (a)): held points are untouchable until the payment ends - a few
 * minutes at most. No number is shown, as `DeductionTooLarge` shows none.
 */
final class PointsHeld extends LoyaltyError
{
    public function __construct(public readonly int $free, public readonly int $held)
    {
        parent::__construct("Only {$free} points are free; {$held} are held for a payment.");
    }

    public function type(): string
    {
        return 'loyalty.points_held';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
