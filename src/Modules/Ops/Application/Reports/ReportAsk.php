<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Modules\Access\Public\Dto\StaffDto;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\PermissionScope;

/**
 * A report as a request names it, before anything is read: the report, the staff member asking, the store
 * - none for every store's - and the permissions the handler checks there (ops.md §3).
 */
final readonly class ReportAsk
{
    /**
     * @param  non-empty-list<string>  $permissions
     */
    public function __construct(
        public ReportKind $kind,
        public StaffDto $person,
        public ?StoreDto $store,
        public array $permissions,
    ) {}

    /** Where the permissions are checked: the store, or every store. */
    public function scope(): PermissionScope
    {
        return $this->store === null ? PermissionScope::allStores() : PermissionScope::store($this->store->storeId());
    }
}
