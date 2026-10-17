<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\SwitchCoupon;

final readonly class SwitchCoupon
{
    public function __construct(
        public string $couponId,
        public bool $active,
    ) {}
}
