<?php

declare(strict_types=1);

namespace Modules\Access\Application\StaffView;

use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Platform\Public\Contracts\OffStoreViewer;
use Shared\Domain\ValueObject\StoreId;

/**
 * Which off stores a staff view may see in the shop (spec §1.11; platform.md §1.6, §2.6): every one,
 * for a Super Admin - the one who prepares a store before it opens - and none for anyone else (the
 * owner, 2026-10-06: "staff cant view an off store, only super admin can"). Nobody without a pass
 * sees any.
 *
 * Read for the pass's staff member by id, not for the request's actor: the shop's request stays a
 * guest's during a staff view.
 */
final readonly class StaffViewOffStores implements OffStoreViewer
{
    public function __construct(
        private StaffViews $views,
        private GrantsReader $grants,
    ) {}

    public function mayView(StoreId $store): bool
    {
        $pass = $this->views->current();

        if ($pass === null) {
            return false;
        }

        $grants = $this->grants->forStaff($pass->staffId);

        return $grants !== null && $grants->isActive() && $grants->superAdmin;
    }
}
