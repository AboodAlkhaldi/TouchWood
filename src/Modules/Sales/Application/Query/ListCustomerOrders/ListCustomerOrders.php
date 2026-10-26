<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListCustomerOrders;

final readonly class ListCustomerOrders
{
    public function __construct(
        public string $customerId,
    ) {}
}
