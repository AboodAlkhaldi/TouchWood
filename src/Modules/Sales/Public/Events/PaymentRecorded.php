<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A payment was recorded on the order (§1.9; ops.md §1.5 C10): by staff here; an online one on an order
 * that exists, by stage 7's own handlers (payments.md). `moneyRecordId` names it.
 *
 * Ids only, through the outbox, after commit (sales.md §6; ops.md §1.5, §2.3).
 */
final readonly class PaymentRecorded implements ShouldDispatchAfterCommit
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
