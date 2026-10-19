<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Feedback\Domain\Repository\Wishlist;

/**
 * Access anonymized an account (feedback.md §1.5, §6): its wishlist, chosen by the account for itself,
 * is deleted; its reviews and questions stay, signed "Deleted customer" at read time. From the queue,
 * retried on failure; done once however many times it runs — an empty list stays empty.
 */
final class ClearAnonymizedWishlist implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly Wishlist $wishlist,
    ) {}

    public function handle(CustomerAnonymized $event): void
    {
        $this->wishlist->clear(strtolower($event->customerId));
    }
}
