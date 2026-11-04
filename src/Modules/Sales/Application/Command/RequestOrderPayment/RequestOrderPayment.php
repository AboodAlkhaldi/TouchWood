<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RequestOrderPayment;

final readonly class RequestOrderPayment
{
    public function __construct(
        public string $orderId,
    ) {}
}
