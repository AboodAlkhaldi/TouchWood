<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Ops\Application\Newsletter\CustomerSubscriptions;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;

/**
 * Access anonymized an account (ops.md §6): the customer's subscriber row and the newsletter provider's
 * contact are deleted, and so are their inbox entries and the messages addressed to them - the record
 * of each email sent to them, and a staff member's message written to them alone.
 *
 * From the queue, retried on failure; once per event (`ops.handled_events`), in one transaction with
 * its work.
 */
final class ForgetAnonymizedCustomer implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CustomerSubscriptions $subscriptions,
        private readonly HandledEvents $handled,
        private readonly ConnectionInterface $db,
    ) {}

    public function handle(CustomerAnonymized $event): void
    {
        $customerId = strtolower($event->customerId);

        $this->handled->once($event->eventId, self::class, function () use ($customerId): void {
            $this->subscriptions->forget($customerId);
            $this->db->table('ops.inbox_entries')->where('customer_id', $customerId)->delete();
            $this->db->table('ops.sent_messages')->where('customer_id', $customerId)->delete();
            $this->db->table('ops.staff_messages')->where('customer_id', $customerId)->delete();
        });
    }
}
