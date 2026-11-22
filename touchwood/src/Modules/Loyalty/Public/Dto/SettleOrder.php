<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

/** An order cancelled before it shipped (loyalty.md §1.8, §2.2): every point it used comes back. */
final readonly class SettleOrder
{
    public function __construct(
        public string $orderId,
        public string $storeId,
        public CallerPermission $caller,
    ) {}
}
