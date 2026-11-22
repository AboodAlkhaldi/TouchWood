<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Payments\Application\Attempt\AttemptSweep;

/**
 * The hourly backstop (payments.md §1.1 rule 9, §9.6 #4).
 */
final class SweepAttemptsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries; the next hour mends a miss. */
    public int $tries = 3;

    public function handle(AttemptSweep $sweep): void
    {
        $sweep->sweep();
    }
}
