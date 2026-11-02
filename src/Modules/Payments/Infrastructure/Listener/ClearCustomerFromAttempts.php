<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Listener;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Payments\Domain\Repository\Attempts;

/**
 * Access anonymized an account (payments.md §6, rule 13): the customer's name, email and phone leave its attempts.
 * Calls and notices never held them (§9.11 #6). From the queue, and safe to run again.
 */
final class ClearCustomerFromAttempts implements ShouldQueue
{
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly Attempts $attempts,
    ) {}

    public function handle(CustomerAnonymized $event): void
    {
        $this->attempts->clearCustomer($event->customerId, CarbonImmutable::now()->toDateTimeImmutable());
    }
}
