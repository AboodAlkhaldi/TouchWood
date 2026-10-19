<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Connections;

use Shared\Domain\ValueObject\StoreId;

/**
 * **Where the provider sends a store's notices** (sync.md §1.3, §2.4; S7): our address for that store,
 * `https://…/sync/notify/<store id>`, for the Super Admin to copy into the provider's settings. Bound in
 * Infrastructure to the app's own address.
 */
interface NoticeAddresses
{
    public function for(StoreId $store): string;
}
