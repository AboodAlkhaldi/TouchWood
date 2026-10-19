<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Feedback\Domain\Repository\Wishlist;

/**
 * Access anonymized an account (feedback.md §1.5, §6): its wishlist, chosen by the account for itself,
 * is deleted; its reviews and questions stay, signed "Deleted customer" at read time. From the queue,
 * retried on failure; done once however many times it runs — an empty list stays empty. It takes the
 * lock a save takes, so a save under way finishes first and is deleted with the rest.
 */
final class ClearAnonymizedWishlist implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly Wishlist $wishlist,
        private readonly ConnectionInterface $db,
    ) {}

    public function handle(CustomerAnonymized $event): void
    {
        $customerId = strtolower($event->customerId);

        $this->db->transaction(function () use ($customerId): void {
            $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["feedback:wishlist:{$customerId}"], false);
            $this->wishlist->clear($customerId);
        });
    }
}
