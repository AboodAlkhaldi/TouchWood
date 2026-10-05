<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * Says whether the request being answered may see an off store's shop (platform.md §1.6, §2.6).
 *
 * An off store is a 404 in the shop for everyone; a module that knows an exception - Access, for a
 * staff member viewing the shop from the panel (access.md §1.11) - registers one of these with
 * OffStoreViewers. Asked only about a store that is off.
 */
interface OffStoreViewer
{
    public function mayView(StoreId $store): bool;
}
