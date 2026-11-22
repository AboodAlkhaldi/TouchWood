<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\FollowRequestPayment;

final readonly class FollowRequestPayment
{
    public function __construct(
        public string $storeId,
        public string $number,
        public string $requestId,
    ) {}
}
