<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Alerts\EventMessage;
use Modules\Ops\Application\Alerts\EventSender;

/**
 * One email of an event's message (ops.md §1.5, §1.6) to a customer or a staff member, its
 * `ops.sent_messages` row already written QUEUED. It carries ids and counts only; the words are written
 * when it runs. The provider is called outside any transaction, then the row is marked SENT; a retry
 * finds a SENT row and sends nothing. **At least once**: a provider that took the email just before the
 * row could be marked may get it again on the retry. A provider's failure is retried; after the last
 * attempt the row is marked FAILED (§7).
 */
final class SendEventEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: Brevo away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public EventMessage $message,
        public ?string $customerId,
        public ?string $staffId,
        public string $sentId,
    ) {}

    public function handle(EventSender $sender): void
    {
        if ($this->customerId !== null) {
            $sender->emailCustomer($this->message, $this->customerId, $this->sentId);
        } elseif ($this->staffId !== null) {
            $sender->emailStaff($this->message, $this->staffId, $this->sentId);
        }
    }

    public function failed(): void
    {
        app(EventSender::class)->failed($this->sentId);
    }
}
