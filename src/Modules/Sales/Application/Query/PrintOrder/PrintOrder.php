<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\PrintOrder;

final readonly class PrintOrder
{
    public function __construct(
        public string $orderId,
    ) {}
}
