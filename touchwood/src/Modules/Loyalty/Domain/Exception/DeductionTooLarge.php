<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A deduction by hand larger than the balance (loyalty.md §7, §1.4): points are only ever taken
 * from points that are there. The balance is written for the log only: the screen says "fewer",
 * since changing points by hand does not need the right to view them (§3).
 */
final class DeductionTooLarge extends LoyaltyError
{
    public function __construct(public readonly int $balance)
    {
        parent::__construct("A deduction may take at most the balance, {$balance} points.");
    }

    public function type(): string
    {
        return 'loyalty.deduction_too_large';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
