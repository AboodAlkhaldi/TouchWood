<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer's question was rejected, or its answer taken down, **with a reason** (feedback.md §1.3, §6;
 * owner, 2026-10-28) — without one, nothing is sent (§1.1). Ops tells the customer why, reading it through
 * `FeedbackApi::questionForMessage`.
 *
 * Ids only, through the outbox, after commit.
 */
final readonly class QuestionNotPublished implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $questionId,
        public string $productId,
        public string $storeId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
