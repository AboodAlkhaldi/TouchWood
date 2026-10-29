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
 * One batch of a notice or a staff member's message (ops.md §1.7, §1.8): the sender queues the next
 * one itself, in the batch's own transaction. A batch run twice starts from the same cursor and sends
 * nothing twice.
 */
final class SendBroadcastBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: the database or Access away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public Broadcast $broadcast,
        public string $id,
    ) {}

    public function handle(BroadcastSender $sender): void
    {
        $sender->batch($this->broadcast, $this->id);
    }
}
