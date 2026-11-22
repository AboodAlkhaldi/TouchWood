<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\CreateCoupon;

use Modules\Promotions\Application\Command\CouponFields;

final readonly class CreateCoupon
{
    public function __construct(
        public string $storeId,
        public string $code,
        public CouponFields $fields,
        public bool $active = true,
    ) {}
}
