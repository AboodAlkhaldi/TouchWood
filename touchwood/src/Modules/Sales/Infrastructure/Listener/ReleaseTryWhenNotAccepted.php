<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Payments\Public\Events\PaymentNotAccepted;
use Modules\Sales\Application\Checkout\TryRelease;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Shared\Infrastructure\Outbox\ProcessedEvents;

/** `PaymentNotAccepted` for a try (§9.5 #4): a currency refused, so released with a refund due. */
final class ReleaseTryWhenNotAccepted implements ShouldQueue
{
    /** Five attempts over about 36 minutes; the hourly look takes what they leave. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CheckoutTries $checkoutTries,
        private readonly TryRelease $release,
        private readonly ProcessedEvents $processed,
    ) {}

    public function handle(PaymentNotAccepted $event): void
    {
        $try = $this->checkoutTries->ofOrder($event->orderId);

        // A payment request's own, on an order with no try or on one a try made: nothing is written (payments.md §1.5).
        if ($try === null || $event->ownerId !== $try->id) {
            return;
        }

        $this->processed->once($event->eventId, self::class, fn () => $this->release->notAccepted($try->id));
    }
}
