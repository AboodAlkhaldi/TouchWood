<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Ops\Application\Alerts\CustomerMessages;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Sales\Public\Events\ReturnAccepted;
use Modules\Sales\Public\Events\ReturnCompleted;
use Modules\Sales\Public\Events\ReturnReceived;
use Modules\Sales\Public\Events\ReturnRefused;

/**
 * Tells the customer what happened to their return (ops.md §1.5): accepted or refused (C8, refused with
 * the store's words), received (C8b), completed (C9: "a refund of X is owed" - completing moves no
 * money). The return is read through `SalesReports::returnForMessage` when the message is written.
 *
 * From the queue, retried on failure; once per event (`ops.handled_events`).
 */
final class TellCustomerAboutReturn implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CustomerMessages $customers,
        private readonly HandledEvents $handled,
    ) {}

    public function handle(ReturnAccepted|ReturnRefused|ReturnReceived|ReturnCompleted $event): void
    {
        $kind = match (true) {
            $event instanceof ReturnAccepted => MessageKind::ReturnAccepted,
            $event instanceof ReturnRefused => MessageKind::ReturnRefused,
            $event instanceof ReturnReceived => MessageKind::ReturnReceived,
            $event instanceof ReturnCompleted => MessageKind::ReturnCompleted,
        };

        $this->handled->once($event->eventId, self::class, fn () => $this->customers->tell(
            new EventMessage($kind, strtolower($event->storeId), ['returnId' => strtolower($event->returnId)]),
            $event->customerId,
        ));
    }
}
