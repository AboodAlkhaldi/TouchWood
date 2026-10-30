<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\DownloadDailyReport;

use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Application\DailyReport\ReportFiles;
use Modules\Ops\Domain\Exception\DailyReportNotFound;
use Modules\Ops\Domain\Repository\DailyReports;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * A kept daily report's PDF (ops.md §1.11): to a reader who may list it - a store's, with
 * `ops.report.daily` there; every store's, a super admin. Any other, or one that does not exist, is
 * "not found" alike.
 */
final readonly class DownloadDailyReportHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private DailyReportPeople $people,
        private DailyReports $reports,
        private ReportFiles $files,
    ) {}

    /** @throws Unauthorized|DailyReportNotFound */
    public function handle(DownloadDailyReport $query): DailyReportDownload
    {
        $person = $this->people->person();
        $id = strtolower(trim($query->report));
        $report = Ulids::valid($id) ? $this->reports->find($id) : null;
        if ($report === null) {
            throw new DailyReportNotFound;
        }

        try {
            if ($report->store === null) {
                $this->authorizer->authorize(DailyReportPeople::PERMISSION, PermissionScope::allStores());
                $this->people->requireAllStores($person);
            } else {
                $this->authorizer->authorize(DailyReportPeople::PERMISSION, PermissionScope::store($report->store));
                $this->people->requireStore($report->store);
            }
        } catch (Unauthorized) {
            throw new DailyReportNotFound;
        }

        $pdf = $this->files->get($report->file) ?? throw new DailyReportNotFound;
        $code = $report->store === null ? 'all' : $this->people->store($report->store->value)->code;

        return new DailyReportDownload('daily-report-'.$code.'-'.$report->day.'.pdf', $pdf);
    }
}
