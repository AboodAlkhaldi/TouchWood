<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Command\SendDailyReportEmail\SendDailyReportEmail;
use Modules\Ops\Application\Command\SendDailyReportEmail\SendDailyReportEmailHandler;

/**
 * The daily report's email at its time (ops.md §1.11), queued with a delay by the person switching it on
 * and then by itself, a day at a time - so it runs as the system on that person's behalf and reads only
 * what they may see. One try: the next day is queued before any work, and a failed making waits in the
 * failed jobs.
 */
final class SendDailyReportEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $settingId,
        public int $version,
        /** The moment it is due, UTC with its offset. */
        public string $due,
    ) {}

    public function handle(SendDailyReportEmailHandler $handler): void
    {
        $handler->handle(new SendDailyReportEmail($this->settingId, $this->version, CarbonImmutable::parse($this->due)->toDateTimeImmutable()));
    }
}
