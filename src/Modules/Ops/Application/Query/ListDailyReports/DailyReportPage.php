<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListDailyReports;

use Modules\Ops\Domain\Model\DailyReportFile;

/** A page of the daily reports kept, and the cursor of the next (none on the last). */
final readonly class DailyReportPage
{
    /**
     * @param  list<DailyReportFile>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
