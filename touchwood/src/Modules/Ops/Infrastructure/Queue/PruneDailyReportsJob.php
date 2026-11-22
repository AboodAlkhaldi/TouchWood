<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Command\PruneDailyReports\PruneDailyReports;
use Modules\Ops\Application\Command\PruneDailyReports\PruneDailyReportsHandler;

/** The nightly removal of the daily reports twelve months old (ops.md §5), queued by the scheduler. */
final class PruneDailyReportsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try: the next night takes whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(PruneDailyReportsHandler $handler): void
    {
        $handler->handle(new PruneDailyReports);
    }
}
