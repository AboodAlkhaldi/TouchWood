<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An order's shipment reached the customer (§1.5) — one of handoff §4.5's critical events, published through the outbox.
 *
 * Ids only, after commit.
 */
final readonly class OrderDelivered implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
