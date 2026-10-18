<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\CustomerPointsInStores;

/**
 * One customer's points in each store the reader may read points in (loyalty.md §4.1 L5): the staff
 * customer page's Points section.
 */
final readonly class CustomerPointsInStores
{
    public function __construct(
        public string $customerId,
    ) {}
}
