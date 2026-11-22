<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Ops\Application\Alerts\CustomerMessages;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Sales\Public\Events\PaymentRecorded;
use Modules\Sales\Public\Events\RefundRecorded;

/**
 * Tells the customer money moved on their order (ops.md §1.5): a refund sent (C9b, the amount and the way),
 * a payment received (C10). The record is read through `SalesReports::moneyRecordForMessage` when the message
 * is written; a voided one sends nothing (owner, 2026-10-29).
 *
 * An online **checkout** gets one email, C1 (§1.5 C10): Sales' event is for money recorded on an order that
 * already exists, so nothing here tells the two apart - the rule is where the event is fired.
 *
 * From the queue, retried on failure; once per event (`ops.handled_events`).
 */
final class TellCustomerAboutMoney implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CustomerMessages $customers,
        private readonly HandledEvents $handled,
    ) {}

    public function handle(PaymentRecorded|RefundRecorded $event): void
    {
        $kind = $event instanceof RefundRecorded ? MessageKind::RefundSent : MessageKind::PaymentReceived;

        $this->handled->once($event->eventId, self::class, fn () => $this->customers->tell(
            new EventMessage($kind, strtolower($event->storeId), ['moneyRecordId' => strtolower($event->moneyRecordId)]),
            $event->customerId,
        ));
    }
}
