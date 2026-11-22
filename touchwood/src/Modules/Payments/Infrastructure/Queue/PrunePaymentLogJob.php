<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Payments\Application\Attempt\PaymentLogPruning;

/**
 * Old notices and calls removed each day (payments.md §5).
 */
final class PrunePaymentLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries; the next day mends a miss. */
    public int $tries = 3;

    public function handle(PaymentLogPruning $pruning): void
    {
        $pruning->prune();
    }
}
