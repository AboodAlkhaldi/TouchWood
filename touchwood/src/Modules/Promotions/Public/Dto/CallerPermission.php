<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

/**
 * The permission that allowed a caller's write (promotions.md §2.1) — Sales's placing an order, its
 * edit. **No scope**: Promotions builds it from the store the write touches — the coupon's own store,
 * the order's — so a caller cannot write one store's coupons with a permission held in another. The
 * permission must belong to the calling module, as Loyalty's (loyalty.md §2.1).
 */
final readonly class CallerPermission
{
    public function __construct(
        /** The calling module, e.g. "sales". */
        public string $module,
        /** Its permission, e.g. "sales.order.place"; refused unless it starts with the module. */
        public string $permission,
    ) {}
}
