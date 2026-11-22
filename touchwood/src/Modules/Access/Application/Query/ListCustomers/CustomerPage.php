<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListCustomers;

/**
 * One page of customers, and how many there are in all.
 */
final readonly class CustomerPage
{
    /**
     * @param  list<CustomerSummary>  $customers
     */
    public function __construct(
        public array $customers,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
