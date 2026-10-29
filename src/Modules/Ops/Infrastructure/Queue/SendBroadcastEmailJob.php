<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Infrastructure\Delivery\BroadcastSender;
use Modules\Ops\Infrastructure\Eloquent\HandledEvents;

/**
 * One email of a notice or a staff member's message (ops.md §1.7, §1.8), sent once by its delivery id
 * (`ops.handled_events`): a retry after it left finds the id and sends nothing. A provider's failure is
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
        public string $deliveryId,
    ) {}

    public function handle(BroadcastSender $sender, HandledEvents $handled): void
    {
        $handled->once($this->deliveryId, self::class, fn () => $sender->email($this->broadcast, $this->id, $this->customerId));
    }

    public function failed(): void
    {
        app(BroadcastSender::class)->emailFailed($this->broadcast, $this->id, $this->customerId);
    }
}
