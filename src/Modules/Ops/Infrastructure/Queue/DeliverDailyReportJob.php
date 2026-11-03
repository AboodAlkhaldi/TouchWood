<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Command\DeliverDailyReport\DeliverDailyReport;
use Modules\Ops\Application\Command\DeliverDailyReport\DeliverDailyReportHandler;

/**
 * Emails a made daily report (ops.md §1.11). Tried again when the provider fails, then left in the
 * failed jobs (§7: never lost silently); the report itself is made once, by the job before it.
 */
final class DeliverDailyReportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Five attempts over about 36 minutes: Brevo away, as every other email waits it out. */
    public int $tries = 5;

    /** @var list<int> seconds before each try again */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        public string $reportId,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ProviderFailureRules];
    }

    public function handle(DeliverDailyReportHandler $handler): void
    {
        $handler->handle(new DeliverDailyReport($this->reportId));
    }
}
