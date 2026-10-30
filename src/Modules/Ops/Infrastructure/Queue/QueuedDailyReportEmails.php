<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Ops\Application\DailyReport\DailyReportEmailQueue;
use Modules\Ops\Infrastructure\Eloquent\Moment;

/**
 * The daily report's email on the database queue (ops.md §1.11): each job carries whoever was acting when
 * it was queued (Platform's QueuedActor), so the email runs on the person's behalf.
 */
final readonly class QueuedDailyReportEmails implements DailyReportEmailQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function send(string $settingId, int $version, DateTimeImmutable $at): void
    {
        $this->bus->dispatch((new SendDailyReportEmailJob($settingId, $version, Moment::of($at)))->delay($at));
    }

    public function deliver(string $reportId): void
    {
        $this->bus->dispatch(new DeliverDailyReportJob($reportId));
    }
}
