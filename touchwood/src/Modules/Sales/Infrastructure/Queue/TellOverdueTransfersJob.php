<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sales\Application\Order\TransferOverdueCheck;

/** The hourly look for transfers past their check time (§9.5 #16). */
final class TellOverdueTransfersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try (platform.md §3): the next hour's run takes whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public int $timeout = 300;

    public function handle(TransferOverdueCheck $check): void
    {
        $check->run();
    }
}
