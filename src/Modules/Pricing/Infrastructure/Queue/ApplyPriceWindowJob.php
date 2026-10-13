<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindow;
use Modules\Pricing\Application\Command\ApplyPriceWindow\ApplyPriceWindowHandler;

/**
 * The timed task at a sale's start or end (pricing.md §1.10), queued delayed to its moment by
 * `QueuedPriceWindowTasks`. One per moment, so never unique.
 */
final class ApplyPriceWindowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Three tries (platform.md §3: every queued job states its own); a task lost even so is the daily
     * check's (`CheckPriceWindowsJob`).
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $storeId,
        public readonly string $saleId,
        public readonly string $moment,
    ) {}

    public function handle(ApplyPriceWindowHandler $handler): void
    {
        $handler->handle(new ApplyPriceWindow($this->storeId, $this->saleId, $this->moment));
    }
}
