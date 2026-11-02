<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The gateway confirmed a successful **transaction** (payments.md §2.2, §1.4 step 6): once per transaction, and
 * not for a currency mismatch (rule 8). `orderId` is the order's id, the future one for a try.
 *
 * Ids only, through the outbox.
 */
final readonly class PaymentSucceeded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $paymentId,
        public string $orderId,
        public string $storeId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
