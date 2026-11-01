<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Delivery;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Application\Delivery\BroadcastQueue;
use Modules\Ops\Infrastructure\Queue\SendBroadcastBatchJob;

/** `BroadcastQueue` on Laravel's queue: the first batch, which queues the rest (BroadcastSender). */
final readonly class QueuedBroadcasts implements BroadcastQueue
{
    public function __construct(
        private Dispatcher $jobs,
    ) {}

    public function start(Broadcast $broadcast, string $id): void
    {
        $this->jobs->dispatch((new SendBroadcastBatchJob($broadcast, $id))->beforeCommit());
    }
}
