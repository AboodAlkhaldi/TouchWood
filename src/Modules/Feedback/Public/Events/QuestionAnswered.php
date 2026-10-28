<?php

declare(strict_types=1);

namespace Modules\Feedback\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer's question was answered by the store's staff (feedback.md §1.3, §6; ops.md §1.5 C16) — Ops
 * tells the customer, reading the answer through `FeedbackApi::questionForMessage`.
 *
 * Ids only, through the outbox, after commit.
 */
final readonly class QuestionAnswered implements ShouldDispatchAfterCommit
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
