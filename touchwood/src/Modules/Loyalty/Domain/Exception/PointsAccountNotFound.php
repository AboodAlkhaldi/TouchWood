<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A staff read or change in a store the staff member does not cover, or for a customer with no
 * account there where one is needed — **the same answer for both** (loyalty.md §7), so the answer
 * never tells whether a customer has points in a store the reader may not see.
 */
final class PointsAccountNotFound extends LoyaltyError
{
    public function __construct()
    {
        parent::__construct('No points account was found.');
    }

    public function type(): string
    {
        return 'loyalty.points_account_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
