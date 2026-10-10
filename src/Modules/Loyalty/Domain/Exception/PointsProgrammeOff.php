<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Redeeming in a store whose points programme is off (loyalty.md §7, §1.5).
 */
final class PointsProgrammeOff extends LoyaltyError
{
    public function __construct()
    {
        parent::__construct('The points programme is off in this store.');
    }

    public function type(): string
    {
        return 'loyalty.points_programme_off';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
