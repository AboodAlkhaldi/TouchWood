<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\AssignCoupon;

final readonly class AssignCoupon
{
    public function __construct(
        public string $couponId,
        public string $segmentId,
    ) {}
}
