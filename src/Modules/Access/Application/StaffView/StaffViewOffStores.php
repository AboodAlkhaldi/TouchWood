<?php

declare(strict_types=1);

namespace Modules\Access\Application\StaffView;

use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Platform\Public\Contracts\OffStoreViewer;
use Shared\Domain\ValueObject\StoreId;

/**
 * Which off stores a staff view may see in the shop (spec §1.11; platform.md §1.6, §2.6): those the
 * staff member covers, as the panel's store switcher lists them - a Super Admin every store, anyone
 * else their assignment's, an exception's stores included. Nobody without a pass sees any.
 *
 * Read for the pass's staff member by id, not for the request's actor: the shop's request stays a
 * guest's during a staff view.
 */
final readonly class StaffViewOffStores implements OffStoreViewer
{
    public function __construct(
        private StaffViews $views,
        private GrantsReader $grants,
        private RoleAssignmentRepository $assignments,
    ) {}

    public function mayView(StoreId $store): bool
    {
        $pass = $this->views->current();

        if ($pass === null) {
            return false;
        }

        $grants = $this->grants->forStaff($pass->staffId);

        if ($grants === null || ! $grants->isActive()) {
            return false;
        }

        if ($grants->superAdmin) {
            return true;
        }

        return $this->assignments->byStaff($pass->staffId)?->staffStores()->covers($store) ?? false;
    }
}
