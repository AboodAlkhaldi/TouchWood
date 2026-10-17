<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Command\UpdateCouponUse;

use Modules\Promotions\Public\Dto\UpdateCouponUse;

final readonly class UpdateCouponUseCommand
{
    public function __construct(
        public UpdateCouponUse $change,
    ) {}
}
