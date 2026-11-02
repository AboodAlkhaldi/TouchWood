<?php

declare(strict_types=1);

namespace Modules\Payments\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An attempt ended with nothing paid (payments.md §2.2): the gateway refused the first call, nothing reached it,
 * or the hold limit passed (rule 15). Sales releases the try. `orderId` keeps it in order with the order's other
 * payment events.
 *
 * Ids only, through the outbox.
 */
final readonly class PaymentNotTaken implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $attemptId,
        public string $ownerId,
        public string $orderId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
