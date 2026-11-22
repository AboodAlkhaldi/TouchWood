<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Money was taken but cannot enter an order: a currency mismatch (payments.md §2.2, rule 8). Sales releases the
 * try and records a refund due on it.
 *
 * Ids only, through the outbox.
 */
final readonly class PaymentNotAccepted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $attemptId,
        public string $ownerId,
        public string $orderId,
        public string $storeId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
