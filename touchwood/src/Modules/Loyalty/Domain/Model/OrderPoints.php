<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model;

use Shared\Domain\ValueObject\StoreId;

/**
 * What Loyalty has recorded of one order (loyalty.md §5, `loyalty.orders`): written when the order
 * redeems points or is delivered, even when no point moves. Its store is the one every later change
 * to the order is checked in (§2.1); its account is the customer's in that store.
 */
final readonly class OrderPoints
{
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public string $accountId,
        public StoreId $store,
        public int $pointsRedeemed,
        public int $discountMinor,
        public int $pointsEarned,
        public bool $delivered,
        public bool $cancelled,
    ) {}
}
