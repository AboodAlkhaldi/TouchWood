<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Money was given back on the order and recorded (§1.9) — the moment the customer has it back, not a
 * return completing (which only works out what is owed). `moneyRecordId` names it.
 *
 * Ids only, through the outbox, after commit (sales.md §6; ops.md §1.5, §2.3).
 */
final readonly class RefundRecorded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $moneyRecordId,
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
