<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Sales\Public\Events\OrderCancelled;

/**
 * A cancelled order's open payment request is withdrawn (payments.md §6). Sales tells Payments, which depends on
 * nothing of Sales. From the queue, and safe to run again.
 */
final class WithdrawRequestWhenCancelled implements ShouldQueue
{
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly PaymentsApi $payments,
    ) {}

    public function handle(OrderCancelled $event): void
    {
        $this->payments->withdrawForCancelled($event->orderId);
    }
}
