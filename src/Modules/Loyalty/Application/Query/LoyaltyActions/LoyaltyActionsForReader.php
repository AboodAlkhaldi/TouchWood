<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\LoyaltyActions;

use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\StaffPointsAction;
use Modules\Loyalty\Application\Query\CustomerName;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Loyalty's staff screens may offer the person looking (loyalty.md §4.1), asked here because a
 * screen may not ask the authorizer itself (tests/Architecture/AccessDecisionsTest). Offering is not
 * allowing: every handler behind a button asks again (handoff §19).
 */
final readonly class LoyaltyActionsForReader
{
    public function __construct(
        private StaffPointsAction $staff,
    ) {}

    /**
     * L2's header and card for one customer in one store: who they are, as the reader may see them;
     * whether Change Points… is offered; whether Open Customer is.
     *
     * @throws PointsAccountNotFound for a customer Access does not have
     */
    public function forCustomer(string $customerId, StoreId $store): CustomerActions
    {
        $customer = $this->staff->existingCustomer($customerId);
        $mayView = $this->staff->mayViewCustomer($customer);

        return new CustomerActions(
            CustomerName::of(strtolower($customer->id), $customer, $mayView),
            $this->staff->holds(LoyaltyPermissions::ADJUST, $store),
            $mayView && ! $customer->anonymized,
        );
    }
}
