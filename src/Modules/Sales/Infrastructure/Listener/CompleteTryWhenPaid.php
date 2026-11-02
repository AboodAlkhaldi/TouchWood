<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Payments\Public\Events\PaymentSucceeded;
use Modules\Sales\Application\Checkout\TryCompletion;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Shared\Infrastructure\Outbox\ProcessedEvents;

/** `PaymentSucceeded` for a try (§9.5 #4): once per event; the page may have completed it already. */
final class CompleteTryWhenPaid implements ShouldQueue
{
    /** Five attempts over about 36 minutes; the hourly look takes what they leave. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CheckoutTries $checkoutTries,
        private readonly TryCompletion $completion,
        private readonly ProcessedEvents $processed,
    ) {}

    public function handle(PaymentSucceeded $event): void
    {
        $try = $this->checkoutTries->ofOrder($event->orderId);

        // A payment request's own order, not a try's.
        if ($try === null) {
            return;
        }

        $this->processed->once($event->eventId, self::class, fn () => $this->completion->told($try->id, $event->paymentId));
    }
}
