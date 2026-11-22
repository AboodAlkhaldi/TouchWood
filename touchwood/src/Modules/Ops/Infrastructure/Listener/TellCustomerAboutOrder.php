<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Ops\Application\Alerts\CustomerMessages;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Sales\Public\Events\OrderApproved;
use Modules\Sales\Public\Events\OrderCameBack;
use Modules\Sales\Public\Events\OrderCancelled;
use Modules\Sales\Public\Events\OrderDelivered;
use Modules\Sales\Public\Events\OrderEdited;
use Modules\Sales\Public\Events\OrderPlaced;
use Modules\Sales\Public\Events\OrderRejected;
use Modules\Sales\Public\Events\OrderShipped;

/**
 * Tells the customer what happened to their order (ops.md §1.5): taken (C1), approved (C2), rejected
 * (C3, with staff's reason when given), shipped (C4), delivered (C5, with the store's return window),
 * cancelled (C6, by them or by staff), changed (C6b, the new lines and total), came back (C7). The order
 * is read through `SalesReports::orderForMessage` when the message is written.
 *
 * From the queue, retried on failure; once per event (`ops.handled_events`), in one transaction with
 * the bell entry and the queued email. It never writes Sales' tables and never blocks the sender.
 */
final class TellCustomerAboutOrder implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CustomerMessages $customers,
        private readonly HandledEvents $handled,
    ) {}

    public function handle(OrderPlaced|OrderApproved|OrderRejected|OrderShipped|OrderDelivered|OrderCancelled|OrderEdited|OrderCameBack $event): void
    {
        $kind = match (true) {
            $event instanceof OrderPlaced => MessageKind::OrderTaken,
            $event instanceof OrderApproved => MessageKind::OrderApproved,
            $event instanceof OrderRejected => MessageKind::OrderRejected,
            $event instanceof OrderShipped => MessageKind::OrderShipped,
            $event instanceof OrderDelivered => MessageKind::OrderDelivered,
            $event instanceof OrderCancelled => MessageKind::OrderCancelled,
            $event instanceof OrderEdited => MessageKind::OrderChanged,
            $event instanceof OrderCameBack => MessageKind::OrderCameBack,
        };

        $this->handled->once($event->eventId, self::class, fn () => $this->customers->tell(
            new EventMessage($kind, strtolower($event->storeId), ['orderId' => strtolower($event->orderId)]),
            $event->customerId,
        ));
    }
}
