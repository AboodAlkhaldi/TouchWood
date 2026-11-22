<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A return completed (sales.md §2.2; handoff §4.5's critical events): Feedback flips the reviews of
 * the products it took back to "Returned", reading them through `SalesApi::returnedProducts`.
 *
 * Ids only, after commit. Declared before Sales is built; Sales publishes it through its outbox.
 */
final readonly class ReturnCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $returnId,
        public string $orderId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
