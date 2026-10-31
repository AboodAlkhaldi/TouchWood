<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

/**
 * Confirming held points when the paid order is made - with its number - or releasing them when the try
 * does not pay (number null) (loyalty.md §9.6). The store is the order's.
 */
final readonly class SettlePointsHold
{
    public function __construct(
        public string $storeId,
        public string $orderId,
        public ?string $orderNumber,
        public CallerPermission $caller,
    ) {}
}
