<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * At placement, the redemption no longer works out as quoted — the balance spent elsewhere, the
 * settings changed — so checkout quotes again (loyalty.md §7, §1.7).
 */
final class RedemptionChanged extends LoyaltyError
{
    public function __construct()
    {
        parent::__construct('The points no longer work out as quoted.');
    }

    public function type(): string
    {
        return 'loyalty.redemption_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
