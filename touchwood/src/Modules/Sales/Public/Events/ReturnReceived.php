<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Staff received a return's pieces (§1.8).
 *
 * Ids only, through the outbox, after commit (sales.md §6; ops.md §1.5, §2.3).
 */
final readonly class ReturnReceived implements ShouldDispatchAfterCommit
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
