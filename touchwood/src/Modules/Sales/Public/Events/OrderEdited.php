<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Staff edited an order before shipping (§1.7) — its lines, address or totals changed; `editId` names the
 * edit.
 *
 * Ids only, through the outbox, after commit (sales.md §6; ops.md §1.5, §2.3).
 */
final readonly class OrderEdited implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $editId,
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
