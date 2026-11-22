<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Content\Application\Command\QueueWantedSitemaps\QueueWantedSitemaps;
use Modules\Content\Application\Command\QueueWantedSitemaps\QueueWantedSitemapsHandler;

/** Every ten minutes: the sitemaps' build a change wants, queued when it may run (content.md §6). */
final class QueueWantedSitemapsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try: the next tick, ten minutes on, is the retry. */
    public int $tries = 1;

    public function handle(QueueWantedSitemapsHandler $handler): void
    {
        $handler->handle(new QueueWantedSitemaps);
    }
}
