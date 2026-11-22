<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

/** A page of staff's order list, newest first; `next` the order id Show More goes on after. */
final readonly class StaffOrdersPage
{
    /**
     * @param  list<StaffOrderRow>  $orders
     * @param  array<string, int>  $counts  each filter's orders for the same stores and search, when asked; else none
     */
    public function __construct(
        public array $orders,
        public ?string $next,
        public array $counts = [],
    ) {}
}
