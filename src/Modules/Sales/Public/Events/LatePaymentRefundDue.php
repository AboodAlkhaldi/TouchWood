<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A payment succeeded after its try was released (payments.md §1.7): the money was taken and no order was made, so a
 * refund is due. Ops tells staff, as it does for a currency refused (ops.md S7). `attemptId` names the payment in
 * Payments' staff list. `orderId` is the id the order would have had: no order has it.
 *
 * Ids only, through the outbox, after commit.
 */
final readonly class LatePaymentRefundDue implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $tryId,
        public string $attemptId,
        public string $orderId,
        public string $storeId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
