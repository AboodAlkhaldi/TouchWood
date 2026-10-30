<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use Modules\Access\Public\Dto\StaffDto;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Domain\Model\DailyReportFile;
use Modules\Platform\Public\Dto\StoreDto;

/** Writes the daily report's email in the person's language and sends it, the PDF attached (ops.md §1.11). */
interface DailyReportMailer
{
    /**
     * @param  StoreDto|null  $store  null for every store's report
     *
     * @throws ProviderFailed
     */
    public function send(StaffDto $to, DailyReportFile $report, ?StoreDto $store, string $pdf): void;
}
