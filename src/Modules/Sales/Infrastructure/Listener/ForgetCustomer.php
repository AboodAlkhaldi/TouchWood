<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Domain\Repository\Orders;

/**
 * Access anonymized an account (sales.md §1.12, §6): its carts, in every store, are deleted, and its
 * orders' email is cleared — their name, phone and address stay (a financial record). From the queue,
 * retried on failure; done once however many times it runs — no cart stays no cart, no email stays none.
 */
final class ForgetCustomer implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly Carts $carts,
        private readonly Orders $orders,
    ) {}

    public function handle(CustomerAnonymized $event): void
    {
        $this->carts->deleteForCustomer(strtolower($event->customerId));
        $this->orders->forgetEmail(strtolower($event->customerId));
    }
}
