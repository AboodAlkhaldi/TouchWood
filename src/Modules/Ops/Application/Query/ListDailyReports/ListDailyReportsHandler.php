<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListDailyReports;

use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Repository\DailyReports;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * "Daily reports" (ops.md §1.11, §3): every report kept of the stores the reader holds `ops.report.daily`
 * in, whoever made it; every store's reports to super admins alone.
 */
final readonly class ListDailyReportsHandler
{
    public const int PAGE = 20;

    public function __construct(
        private Authorizer $authorizer,
        private DailyReportPeople $people,
        private DailyReports $reports,
    ) {}

    /** @throws Unauthorized|InvalidOpsInput */
    public function handle(ListDailyReports $query): DailyReportPage
    {
        $person = $this->people->person();
        $stores = $this->authorizer->storesWith(DailyReportPeople::PERMISSION);
        if ($stores === []) {
            throw new Unauthorized(DailyReportPeople::PERMISSION);
        }

        $after = $query->after === null ? null : strtolower(trim($query->after));
        if ($after !== null && ! Ulids::valid($after)) {
            throw new InvalidOpsInput('after', 'not a report of this list');
        }

        $rows = $this->reports->page($stores, $person->isSuperAdmin && $stores === null, $after, self::PAGE + 1);
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new DailyReportPage($rows, $more && $last !== null ? $last->id : null);
    }
}
