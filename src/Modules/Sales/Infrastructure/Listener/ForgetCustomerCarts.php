<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Sales\Domain\Repository\Carts;

/**
 * Access anonymized an account (sales.md §1.12, §6): its carts, in every store, are deleted — the email
 * on its orders is cleared too once orders exist (step S6). From the queue, retried on failure; done
 * once however many times it runs — no cart stays no cart.
 */
final class ForgetCustomerCarts implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly Carts $carts,
    ) {}

    public function handle(CustomerAnonymized $event): void
    {
        $this->carts->deleteForCustomer(strtolower($event->customerId));
    }
}
