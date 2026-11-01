<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Infrastructure\Delivery\BroadcastSender;

/**
 * One email of a notice or a staff member's message (ops.md §1.7, §1.8), its `ops.sent_messages` row already
 * written QUEUED with its batch, as an event's email is: the provider is called outside any transaction,
 * then the row is marked SENT; a retry finds a SENT row and sends nothing. **At least once**: a provider that
 * took the email just before the row could be marked may get it again on the retry. A provider's failure is
 * retried; after the last attempt it is recorded as failed and counted (§7).
 */
final class SendBroadcastEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: Brevo away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public Broadcast $broadcast,
        public string $id,
        public string $customerId,
        public string $sentId,
    ) {}

    public function handle(BroadcastSender $sender): void
    {
        $sender->email($this->broadcast, $this->id, $this->customerId, $this->sentId);
    }

    public function failed(): void
    {
        app(BroadcastSender::class)->emailFailed($this->broadcast, $this->id, $this->sentId);
    }
}
