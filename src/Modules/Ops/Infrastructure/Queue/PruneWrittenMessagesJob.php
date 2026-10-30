<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Command\PruneWrittenMessages\PruneWrittenMessages;
use Modules\Ops\Application\Command\PruneWrittenMessages\PruneWrittenMessagesHandler;

/**
 * The nightly removal of staff messages, old notices and unconfirmed sign-ups (ops.md §5), queued by
 * the scheduler (OpsServiceProvider). Unique: while one waits or runs, no second one is queued.
 */
final class PruneWrittenMessagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try: the next night takes whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(PruneWrittenMessagesHandler $handler): void
    {
        $handler->handle(new PruneWrittenMessages);
    }
}
