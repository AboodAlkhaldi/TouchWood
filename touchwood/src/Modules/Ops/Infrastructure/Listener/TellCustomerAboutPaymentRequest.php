<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Ops\Application\Alerts\CustomerMessages;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Payments\Public\Events\PaymentRequested;

/**
 * Sends the customer the link to pay the rest of their order (ops.md §1.5 C11): staff created a payment request,
 * open 60 minutes. **At once**: no delay and no batching, off the queue like every message; only a security message is
 * sent outside it (C18). The words are written from the request as it stands, so one that ended first sends nothing,
 * when this runs and again when the email leaves.
 *
 * From the queue, retried on failure; once per event (`ops.handled_events`).
 */
final class TellCustomerAboutPaymentRequest implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CustomerMessages $customers,
        private readonly HandledEvents $handled,
    ) {}

    public function handle(PaymentRequested $event): void
    {
        $this->handled->once($event->eventId, self::class, fn () => $this->customers->tell(
            new EventMessage(MessageKind::PayRemaining, strtolower($event->storeId), ['requestId' => strtolower($event->requestId), 'orderId' => strtolower($event->orderId)]),
            $event->customerId,
        ));
    }
}
