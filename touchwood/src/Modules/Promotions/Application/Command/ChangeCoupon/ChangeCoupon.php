<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\ChangeCoupon;

use Modules\Promotions\Application\Command\CouponFields;

final readonly class ChangeCoupon
{
    public function __construct(
        public string $couponId,
        public CouponFields $fields,
    ) {}
}
