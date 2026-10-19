<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Shared\Infrastructure\Outbox\OutboxRelay;

/**
 * The outbox's relay run as queued work (platform.md §5.6; sales.md §5): every minute, from one server.
 * The relay itself is Shared's (`OutboxRelay`, answer 43); Platform, which runs the application's own
 * scheduled work, queues it — so it has a name on the failed jobs screen as every queued job does.
 */
final class RelayOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try (platform.md §3): the next minute's run takes whatever this one left. */
    public int $tries = 1;

    /** Never two runs at once on the queue, the relay's lease covering the rest. */
    public int $uniqueFor = 120;

    /** Well inside the relay's lease, so a run never outlives its claim. */
    public int $timeout = 240;

    public function handle(OutboxRelay $relay): void
    {
        $relay->run();
    }
}
