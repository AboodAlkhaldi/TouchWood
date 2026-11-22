<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A transaction was declined (payments.md §2.2, §1.4 step 6): Sales ends the try and releases everything.
 *
 * Ids only, through the outbox.
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
