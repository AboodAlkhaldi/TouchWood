<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Staff created a payment request (payments.md §1.5, §2.2): Ops sends the customer the link, built from the order's
 * number.
 *
 * Ids only, through the outbox.
 */
final readonly class PaymentRequested implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $requestId,
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $occurredAt,
    ) {}
}
