<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A bank transfer's receipt uploaded more than `transferCheckHours` ago and the order still not paid (sales.md
 * §9.5 #9, #16): once per order, a test order never. Ops tells staff to check it (ops.md S6).
 *
 * Ids only, through the outbox, after commit.
 */
final readonly class TransferOverdue implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $orderId,
        public string $storeId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
