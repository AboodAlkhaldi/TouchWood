<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

use DateTimeImmutable;
use Modules\Payments\Public\Enums\PaymentMode;
use Modules\Payments\Public\Enums\PaymentRequestState;

/**
 * Staff's request for what an order is missing (payments.md §1.5, §5): one open per order, changed only under its
 * row's lock, so a payment and a withdrawal can never pass each other.
 */
final readonly class PaymentRequest
{
    public function __construct(
        public string $id,
        public string $storeId,
        public string $orderId,
        public string $orderNumber,
        public string $customerId,
        public int $amountMinor,
        public string $currencyCode,
        public PaymentMode $mode,
        public PaymentRequestState $state,
        public DateTimeImmutable $expiresAt,
        public string $requestedBy,
        public DateTimeImmutable $createdAt,
    ) {}
}
