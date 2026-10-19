<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Feedback\Application\Review\ReturnedReviews;
use Modules\Sales\Public\Events\ReturnCompleted;

/**
 * Sales completed a return (feedback.md §6): the reviews of what came back read "Returned". From the
 * queue, retried on failure; done once however many times it runs.
 */
final class FlipReturnedReviews implements ShouldQueue
{
    /** Five attempts over about 36 minutes: a lock that waits too long, or the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly ReturnedReviews $reviews,
    ) {}

    public function handle(ReturnCompleted $event): void
    {
        $this->reviews->flip($event->returnId);
    }
}
