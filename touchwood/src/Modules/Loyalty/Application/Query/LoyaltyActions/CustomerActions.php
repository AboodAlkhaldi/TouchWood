<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\LoyaltyActions;

use Modules\Loyalty\Application\Query\CustomerName;

/** What L2 shows and offers for one customer in one store (loyalty.md §4.1). */
final readonly class CustomerActions
{
    public function __construct(
        public CustomerName $customer,
        public bool $mayChangePoints,
        public bool $mayOpenCustomer,
    ) {}
}
