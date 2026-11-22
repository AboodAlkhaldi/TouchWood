<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer's review was rejected, or taken down after approval, **with a reason** (feedback.md §1.1, §6;
 * ops.md §1.5 C17; owner, 2026-10-28) — without one, nothing is sent ("no reason, no message"). Ops tells
 * the customer why, reading it through `FeedbackApi::reviewForMessage`.
 *
 * Ids only, through the outbox, after commit.
 */
final readonly class ReviewNotPublished implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $reviewId,
        public string $productId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
