<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\WithdrawOrderRequest;

final readonly class WithdrawOrderRequest
{
    public function __construct(
        public string $orderId,
    ) {}
}
