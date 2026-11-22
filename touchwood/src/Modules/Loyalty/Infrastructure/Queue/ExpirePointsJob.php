<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Loyalty\Application\Command\ExpirePoints\ExpirePoints;
use Modules\Loyalty\Application\Command\ExpirePoints\ExpirePointsHandler;

/**
 * The nightly expiry, queued by the scheduler at 00:30 UTC (LoyaltyServiceProvider; loyalty.md §1.10),
 * after Access's 00:00 sweep. Scheduled work runs as a queued job (owner's decision, 2026-09-18).
 * Unique: while one waits or runs, no second one is queued.
 */
final class ExpirePointsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One try (platform.md §3: every queued job states its own): every write expires its own
     * account's dead lots anyway, and tomorrow's run takes whatever this one left.
     */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(ExpirePointsHandler $handler): void
    {
        $handler->handle(new ExpirePoints);
    }
}
