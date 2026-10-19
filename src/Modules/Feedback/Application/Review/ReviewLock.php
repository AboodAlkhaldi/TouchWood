<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Review;

use Illuminate\Database\ConnectionInterface;

/**
 * Writing a review and settling a return never miss each other (feedback.md §1.1): both take this lock
 * on (customer, product) first — Feedback has no row of its own to lock (answer 20) — the writer asks
 * Sales after taking it, the return's listener reads the reviews after taking it. Held until the
 * transaction ends.
 */
final readonly class ReviewLock
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function take(string $customerId, string $productId): void
    {
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["feedback:review:{$customerId}:{$productId}"], false);
    }
}
