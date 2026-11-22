<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\CountStoreAudience;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Ops\Application\Delivery\Writers;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The number a confirmation names before a notice or a message goes to a whole store (ops.md §4.1, §9.4 Q14),
 * under `ops.notice.manage` or `ops.message.send_many` there.
 *
 * It is whom the **bell** reaches, the same people the sender walks (AccessApi::customerIdsInStore): every customer
 * registered in the store, not anonymized. Their email goes to fewer, those who may receive offers
 * (MarketingReach), and that number is never shown: it depends on consent, and changes until each email leaves.
 */
final readonly class CountStoreAudienceHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private Writers $writers,
        private AccessApi $access,
    ) {}

    /** @throws InvalidOpsInput|Unauthorized */
    public function handle(CountStoreAudience $query): int
    {
        $store = $this->writers->store($query->storeId);
        $notices = Writers::among($store->storeId(), $this->authorizer->storesWith(OpsPermissions::NOTICE_MANAGE));
        $this->authorizer->authorize($notices ? OpsPermissions::NOTICE_MANAGE : OpsPermissions::MESSAGE_SEND_MANY, PermissionScope::store($store->storeId()));

        return $this->access->customerCountInStore($store->storeId());
    }
}
