<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The customer has as many orders waiting for approval in this store as are allowed — 3 unless an admin
 * of every store changed it (sales.md §1.3, answer 51): another waits until staff decide one.
 */
final class TooManyWaitingOrders extends SalesError
{
    public function __construct(public readonly int $most)
    {
        parent::__construct('Too many orders waiting for approval.');
    }

    public function type(): string
    {
        return 'sales.too_many_waiting_orders';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['most' => $this->most];
    }
}
