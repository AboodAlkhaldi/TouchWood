<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An order fact that cannot follow what Loyalty already recorded (loyalty.md §7, §1.8): a delivery of
 * a cancelled order, a cancellation of a delivered order, a return of an order whose delivery Loyalty
 * never recorded. A cancellation of an order Loyalty has no record of settles nothing and is not this.
 */
final class OrderNotSettleable extends LoyaltyError
{
    public function __construct(public readonly string $orderId, public readonly string $reason)
    {
        parent::__construct("Order {$orderId} cannot be settled: {$reason}");
    }

    public function type(): string
    {
        return 'loyalty.order_not_settleable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
