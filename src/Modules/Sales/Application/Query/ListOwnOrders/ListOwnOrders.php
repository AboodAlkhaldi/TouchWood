<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ListOwnOrders;

final readonly class ListOwnOrders
{
    public function __construct(
        /** The number of the last order shown; null from the top. */
        public ?string $after = null,
    ) {}
}
