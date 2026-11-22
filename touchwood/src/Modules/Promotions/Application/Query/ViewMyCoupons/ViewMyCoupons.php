<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ViewMyCoupons;

/** My coupons in a store, for the account centre (promotions.md §3). */
final readonly class ViewMyCoupons
{
    public function __construct(
        public string $storeId,
    ) {}
}
