<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewOrder;

final readonly class ViewOrder
{
    public function __construct(
        public string $orderId,
    ) {}
}
