<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

/**
 * Confirming a held coupon when the paid order is made - with its number - or releasing it when the try
 * does not pay (number null) (promotions.md §9.5). The store is the order's.
 */
final readonly class SettleReservation
{
    public function __construct(
        public string $storeId,
        public string $orderId,
        public ?string $orderNumber,
        public CallerPermission $caller,
    ) {}
}
