<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Access\Public\Events\CustomerBlocked;
use Modules\Access\Public\Events\CustomerDeletionCancelled;
use Modules\Access\Public\Events\CustomerDeletionScheduled;
use Modules\Access\Public\Events\CustomerEmailVerified;
use Modules\Access\Public\Events\CustomerLocaleChanged;
use Modules\Access\Public\Events\CustomerUnblocked;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Modules\Ops\Application\Newsletter\CustomerSubscriptions;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;

/**
 * Keeps a customer's place on the newsletter list current (ops.md §1.3, §1.9, §6): their consent
 * changed, their language changed (access.md amendment 79) - and the other changes to who may receive
 * marketing email (§1.3, §9.3 #14): an email verified, an account blocked or unblocked, a deletion
 * scheduled or cancelled. Each reads the customer again and puts them on, moves them, or takes them
 * off.
 *
 * From the queue, retried on failure; once per event (`ops.handled_events`). It never writes Access's
 * tables and never blocks the sender.
 */
final class RefreshNewsletterSubscription implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CustomerSubscriptions $subscriptions,
        private readonly HandledEvents $handled,
    ) {}

    public function handle(MarketingConsentChanged|CustomerLocaleChanged|CustomerEmailVerified|CustomerBlocked|CustomerUnblocked|CustomerDeletionScheduled|CustomerDeletionCancelled $event): void
    {
        $this->handled->once($event->eventId, self::class, fn () => $this->subscriptions->refresh($event->customerId));
    }
}
