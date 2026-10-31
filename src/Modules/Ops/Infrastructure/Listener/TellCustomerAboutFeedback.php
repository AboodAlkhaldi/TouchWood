<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Listener;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Feedback\Public\Events\QuestionAnswered;
use Modules\Feedback\Public\Events\QuestionNotPublished;
use Modules\Feedback\Public\Events\ReviewNotPublished;
use Modules\Ops\Application\Alerts\CustomerMessages;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;
use Modules\Ops\Public\Enums\MessageKind;

/**
 * Tells the customer about their question or review (ops.md §1.5): answered (C16), or not published
 * with the reason staff wrote (C17, C17b). Read through `FeedbackApi::questionForMessage` and
 * `reviewForMessage`, and sent **only if it still stands** - the question still answered, the review or
 * question still not published and with a reason (Feedback's events are kept in order per order only).
 *
 * From the queue, retried on failure; once per event (`ops.handled_events`).
 */
final class TellCustomerAboutFeedback implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly CustomerMessages $customers,
        private readonly HandledEvents $handled,
    ) {}

    public function handle(QuestionAnswered|QuestionNotPublished|ReviewNotPublished $event): void
    {
        $store = strtolower($event->storeId);
        $message = match (true) {
            $event instanceof QuestionAnswered => new EventMessage(MessageKind::QuestionAnswered, $store, ['questionId' => strtolower($event->questionId)]),
            $event instanceof QuestionNotPublished => new EventMessage(MessageKind::QuestionNotPublished, $store, ['questionId' => strtolower($event->questionId)]),
            $event instanceof ReviewNotPublished => new EventMessage(MessageKind::ReviewNotPublished, $store, ['reviewId' => strtolower($event->reviewId)]),
        };

        $this->handled->once($event->eventId, self::class, fn () => $this->customers->tell($message, $event->customerId));
    }
}
