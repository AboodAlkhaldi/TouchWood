<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An online payment failed or was abandoned (sales.md §2.4) — one of handoff §4.5's critical events, published by Payments
 * through the outbox from stage 7; Sales records it. Provisional: declared by Sales for stage 7.
 *
 * Ids only, after commit.
 */
final readonly class PaymentFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $paymentId,
        public string $orderId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
