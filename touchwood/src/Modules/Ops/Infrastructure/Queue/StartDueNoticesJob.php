<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Delivery\Broadcast;
use Modules\Ops\Domain\Repository\Notices;

/**
 * Every minute (OpsServiceProvider): the scheduled notices whose time has come start sending (ops.md
 * §1.7), their first batches queued in the same transaction. From then on they cannot be changed or
 * cancelled. Unique: while one waits or runs, no second one is queued.
 */
final class StartDueNoticesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try: the next minute starts whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 300;

    public function handle(ConnectionInterface $db, Notices $notices, Dispatcher $jobs): void
    {
        $db->transaction(function () use ($notices, $jobs): void {
            foreach ($notices->startDue(CarbonImmutable::now()->toDateTimeImmutable()) as $id) {
                $jobs->dispatch(new SendBroadcastBatchJob(Broadcast::Notice, $id));
            }
        });
    }
}
