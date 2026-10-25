<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

/** A page of the customer's orders, newest first; `next` the number Show More goes on after. */
final readonly class OwnOrdersPage
{
    /**
     * @param  list<OwnOrderRow>  $orders
     */
    public function __construct(
        public array $orders,
        public ?string $next,
    ) {}
}
