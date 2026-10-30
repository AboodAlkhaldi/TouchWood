<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\MakeDailyReport;

use Modules\Ops\Application\DailyReport\DailyReportMaker;
use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Model\DailyReportFile;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Makes a daily report on the button (ops.md §1.11, §3): `ops.report.daily` in the store - every store's
 * report a super admin's alone - and keeps it.
 */
final readonly class MakeDailyReportHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private DailyReportPeople $people,
        private DailyReportMaker $maker,
    ) {}

    /** @throws Unauthorized|InvalidOpsInput */
    public function handle(MakeDailyReport $command): DailyReportFile
    {
        $person = $this->people->person();

        if ($command->store === null) {
            $this->authorizer->authorize(DailyReportPeople::PERMISSION, PermissionScope::allStores());
            $this->people->requireAllStores($person);

            return $this->maker->make($person, null, $this->people->everyStore(), $command->day, $this->people->allStoresZone());
        }

        $store = $this->people->store($command->store);
        $this->authorizer->authorize(DailyReportPeople::PERMISSION, PermissionScope::store($store->storeId()));
        $this->people->requireStore($store->storeId());

        return $this->maker->make($person, $store, [$store], $command->day, $store->timezone);
    }
}
