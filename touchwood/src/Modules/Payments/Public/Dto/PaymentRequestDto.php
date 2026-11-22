<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Dto;

use Modules\Payments\Public\Enums\PaymentMode;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * Staff's request for what an order is missing (payments.md §1.5, §2.1). `mode` is the order's: a TEST order is
 * `TEST`, refused when it is not the gateway's, so real money never lands on a test order.
 */
final readonly class PaymentRequestDto
{
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public StoreId $storeId,
        public Money $amount,
        public string $customerId,
        public PaymentMode $mode,
    ) {}
}
