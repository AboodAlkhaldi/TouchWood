<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\UseCoupon;

use Modules\Promotions\Public\Dto\UseCoupon;

final readonly class UseCouponCommand
{
    public function __construct(
        public UseCoupon $change,
    ) {}
}
