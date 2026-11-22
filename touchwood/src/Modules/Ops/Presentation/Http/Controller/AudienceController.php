<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\CountStoreAudience\CountStoreAudience;
use Modules\Ops\Application\Query\CountStoreAudience\CountStoreAudienceHandler;
use Modules\Ops\Presentation\Http\Resource\StoreAudienceData;
use Modules\Platform\Public\Contracts\StoreChoices;
use Shared\Application\Unauthorized;

/*
| A whole store's audience (ops.md §4.1, §9.4 Q14): how many customers a notice or a message to all of them would
| reach, for the confirmation before it goes. JSON, in the store the form shows; who may ask is Ops' answer.
*/
final readonly class AudienceController
{
    public function __invoke(Request $request, StoreChoices $choices, CountStoreAudienceHandler $count): JsonResponse
    {
        $store = $choices->chosen(Inputs::text($request, 'store'), OpsPermissions::NOTICE_MANAGE, OpsPermissions::MESSAGE_SEND_MANY) ?? throw new Unauthorized(OpsPermissions::MESSAGE_SEND_MANY);

        return new JsonResponse((new StoreAudienceData($count->handle(new CountStoreAudience($store->id))))->toArray());
    }
}
