<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use LogicException;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Public\Events\PaymentSucceeded;
use Modules\Sales\Application\Order\OnlineMoney;
use Modules\Sales\Domain\Repository\CheckoutTries;
use Shared\Infrastructure\Outbox\ProcessedEvents;

/**
 * `PaymentSucceeded` for an order that exists (§9.5 #7, #8): an `ONLINE` payment on it, once per event, and
 * `PaymentRecorded` (ops.md C10). Never refused: on a cancelled or paid order the excess shows as a refund due. A
 * try's order is `CompleteTryWhenPaid`'s.
 */
final class RecordRequestPayment implements ShouldQueue
{
    /** Five attempts over about 36 minutes; the page may have recorded it already. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CheckoutTries $checkoutTries,
        private readonly PaymentsApi $payments,
        private readonly OnlineMoney $money,
        private readonly ProcessedEvents $processed,
    ) {}

    public function handle(PaymentSucceeded $event): void
    {
        if ($this->checkoutTries->ofOrder($event->orderId) !== null) {
            return;
        }

        $this->processed->once($event->eventId, self::class, function () use ($event): void {
            $this->money->record(
                $event->orderId,
                $this->payments->payment($event->paymentId) ?? throw new LogicException("No payment {$event->paymentId} of the gateway's."),
                tell: true,
            );
        });
    }
}
