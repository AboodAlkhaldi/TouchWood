<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer asked for a return (§1.8; ops.md §1.6, the owner's "Returns" alert, 2026-10-28).
 *
 * Ids only, through the outbox, after commit (sales.md §6; ops.md §1.5, §2.3).
 */
final readonly class ReturnRequested implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $returnId,
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
