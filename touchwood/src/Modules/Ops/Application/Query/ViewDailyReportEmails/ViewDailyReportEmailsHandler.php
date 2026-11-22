<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewDailyReportEmails;

use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Domain\Model\DailyReportEmail;
use Modules\Ops\Domain\Repository\DailyReports;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/** The reader's own daily report emails, on and off (ops.md §1.11): none until they switch one on. */
final readonly class ViewDailyReportEmailsHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private DailyReportPeople $people,
        private DailyReports $reports,
    ) {}

    /**
     * @return list<DailyReportEmail>
     *
     * @throws Unauthorized
     */
    public function handle(ViewDailyReportEmails $query): array
    {
        $person = $this->people->person();
        if ($this->authorizer->storesWith(DailyReportPeople::PERMISSION) === []) {
            throw new Unauthorized(DailyReportPeople::PERMISSION);
        }

        return $this->reports->emailsOf($person->id);
    }
}
