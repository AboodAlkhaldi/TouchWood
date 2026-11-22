<?php

declare(strict_types=1);

namespace Modules\Shipping\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A new group with an amount another group of this carrier has (rule 4, §1.4): "This carrier already
 * has a 20 group — add the places there", naming that group so the screen links to it.
 */
final class AmountAlreadyUsed extends ShippingError
{
    public function __construct(public readonly string $groupId, public readonly string $amount)
    {
        parent::__construct("This carrier already has a {$this->amount} group.");
    }

    public function type(): string
    {
        return 'shipping.amount_already_used';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['group' => $this->groupId, 'amount' => $this->amount];
    }
}
