<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Modules\Loyalty\Public\Enums\RedemptionRefusal;
use Shared\Domain\Error\ErrorCategory;

/**
 * Placing a redemption the rules refuse (loyalty.md §7), with the same reason a quote would give —
 * one no quote could give: no points to redeem at all, or a discount of 0. A quoted redemption that
 * no longer works out at placement, refused or different, is `RedemptionChanged` (owner, 2026-10-11).
 */
final class RedemptionRefused extends LoyaltyError
{
    public function __construct(public readonly RedemptionRefusal $reason)
    {
        parent::__construct("The points cannot be used on this order: {$reason->value}.");
    }

    public function type(): string
    {
        return 'loyalty.redemption_refused';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['reason' => $this->reason->value];
    }
}
