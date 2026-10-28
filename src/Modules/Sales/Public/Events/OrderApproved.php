<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Staff approved a waiting order (§1.5; ops.md §1.5 C2).
 *
 * Ids only, through the outbox, after commit (sales.md §6; ops.md §1.5, §2.3).
 */
final readonly class OrderApproved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
