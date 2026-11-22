<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Pricing\Application\Command\CheckPriceWindows\CheckPriceWindows;
use Modules\Pricing\Application\Command\CheckPriceWindows\CheckPriceWindowsHandler;

/**
 * The daily safety check (pricing.md §1.10), queued by the scheduler once a night
 * (PricingServiceProvider). Scheduled work runs as a queued job (owner's decision, 2026-09-18). Unique:
 * while one waits or runs, no second one is queued.
 */
final class CheckPriceWindowsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One try (platform.md §3: every queued job states its own): the timed tasks did the work already,
     * and tomorrow's run looks back 25 hours.
     */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(CheckPriceWindowsHandler $handler): void
    {
        $handler->handle(new CheckPriceWindows);
    }
}
