<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Staff rejected a waiting order (§1.5; ops.md §1.5 C3) — with their reason when given, read through
 * Sales's read for messages (ops.md §2.3). A rejection is not a cancellation.
 *
 * Ids only, through the outbox, after commit (sales.md §6; ops.md §1.5, §2.3).
 */
final readonly class OrderRejected implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
