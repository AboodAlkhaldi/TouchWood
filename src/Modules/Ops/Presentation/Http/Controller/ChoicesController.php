<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Presentation\Http\Resource\OpsChoicesData;
use Modules\Ops\Presentation\Http\Resource\ReportChoices;
use Modules\Ops\Presentation\Http\Resource\StoreChoiceData;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;

/*
| What each of Ops' forms may offer the reader (ops.md §4): Platform's StoreChoices for each form's
| permission - a Super Admin every store, anyone else the stores that are on - and the report kinds they may
| open (ReportChoices, which the Reports page reads too). Offering is not allowing: every handler still asks
| for its permission in the store chosen.
|
| Each service is the action's argument, never the controller's: each asks who is acting (lesson 171).
*/
final readonly class ChoicesController
{
    public function __invoke(StoreChoices $choices, ReportChoices $reports): JsonResponse
    {
        // First, as before: someone not an active staff member is refused before anything is read.
        $offered = $reports->offered();
        $superAdmin = $offered['superAdmin'];

        return new JsonResponse((new OpsChoicesData(
            self::stores($choices->forJobs(OpsPermissions::NOTICE_MANAGE)),
            self::stores($choices->forJobs(OpsPermissions::MESSAGE_SEND_ONE)),
            self::stores($choices->forJobs(OpsPermissions::MESSAGE_SEND_MANY)),
            self::stores($choices->forJobs(OpsPermissions::NEWSLETTER_MANAGE)),
            self::stores($choices->forJobs(OpsPermissions::REPORT_DAILY)),
            $superAdmin,
            $offered['reports'],
        ))->toArray());
    }

    /**
     * @param  list<StoreDto>  $stores
     * @return list<StoreChoiceData>
     */
    private static function stores(array $stores): array
    {
        return StoreChoiceData::listOf($stores);
    }
}
