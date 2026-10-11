<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Modules\Loyalty\Public\Enums\RedemptionRefusal;
use Shared\Domain\Error\ErrorCategory;

/**
 * Placing a redemption the rules refuse (loyalty.md §7), with the same reason a quote would give.
 * Worked out again at placement, a redemption the rules now refuse is this; one that still works but
 * no longer as quoted is `RedemptionChanged`.
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
