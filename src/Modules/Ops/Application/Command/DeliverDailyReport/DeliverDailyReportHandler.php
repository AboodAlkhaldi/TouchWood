<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\DeliverDailyReport;

use Carbon\CarbonImmutable;
use Modules\Ops\Application\DailyReport\DailyReportMailer;
use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Application\DailyReport\ReportFiles;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Domain\Repository\DailyReports;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Sends the made report to the person who made it, the PDF attached (ops.md §1.11), and marks it emailed.
 * Its own job, apart from the making: a provider that fails is tried again by the queue without making
 * another report, then lands in the failed jobs - never lost silently (§7). One already emailed is not
 * sent twice.
 */
final readonly class DeliverDailyReportHandler
{
    /** `ops.report.daily` (ops.md §3), in the store or, for every store's report, all stores. */
    public const string PERMISSION = DailyReportPeople::PERMISSION;

    public function __construct(
        private Authorizer $authorizer,
        private DailyReportPeople $people,
        private DailyReports $reports,
        private ReportFiles $files,
        private DailyReportMailer $mailer,
    ) {}

    /** @throws ProviderFailed|Unauthorized */
    public function handle(DeliverDailyReport $command): bool
    {
        $report = $this->reports->find($command->reportId);
        if ($report === null || $report->emailedAt !== null) {
            return false;
        }

        $this->authorizer->authorize(self::PERMISSION, $report->store === null ? PermissionScope::allStores() : PermissionScope::store($report->store));
        $person = $this->people->person();
        $pdf = $this->files->get($report->file);
        if ($person->id !== $report->madeBy || $pdf === null) {
            return false;
        }

        $this->mailer->send($person, $report, $report->store === null ? null : $this->people->store($report->store->value), $pdf);
        $this->reports->markEmailed($report->id, CarbonImmutable::now()->toDateTimeImmutable());

        return true;
    }
}
