<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\DeleteCoupon;

final readonly class DeleteCoupon
{
    public function __construct(
        public string $couponId,
    ) {}
}
