<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\RemoveAssignment;

final readonly class RemoveAssignment
{
    public function __construct(
        public string $couponId,
        public string $customerId,
    ) {}
}
