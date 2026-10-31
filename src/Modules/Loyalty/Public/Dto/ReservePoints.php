<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Modules\Loyalty\Public\Enums\Audience;
use Shared\Domain\ValueObject\Money;

/**
 * Holding the quoted points while the customer pays online (loyalty.md §9.6): as `RedeemPoints`, under
 * the order's future id and with no order number yet.
 */
final readonly class ReservePoints
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public string $storeId,
        public int $points,
        public Money $discount,
        public Audience $audience,
        public Money $netSubtotal,
        public CallerPermission $caller,
    ) {}
}
