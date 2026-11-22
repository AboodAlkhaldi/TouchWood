<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Loyalty\Public\Events\PointsChangedByHand;
use Modules\Loyalty\Public\Events\PointsEarned;
use Modules\Ops\Application\Alerts\CustomerMessages;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;

/**
 * Tells the customer about their points (ops.md §1.5): earned on a delivered order (C12), changed by
 * an admin (C13, with the admin's reason when given). The entry is read through
 * `LoyaltyApi::entryForMessage` when the message is written. Points taken back and points expired send
 * nothing (§9.3 #9).
 *
 * From the queue, retried on failure; once per event (`ops.handled_events`).
 */
final class TellCustomerAboutPoints implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CustomerMessages $customers,
        private readonly HandledEvents $handled,
    ) {}

    public function handle(PointsEarned|PointsChangedByHand $event): void
    {
        $kind = $event instanceof PointsEarned ? MessageKind::PointsEarned : MessageKind::PointsChangedByHand;

        $this->handled->once($event->eventId, self::class, fn () => $this->customers->tell(
            new EventMessage($kind, strtolower($event->storeId), ['entryId' => $event->entryId]),
            $event->customerId,
        ));
    }
}
