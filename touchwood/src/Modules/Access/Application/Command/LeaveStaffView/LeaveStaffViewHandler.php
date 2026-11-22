<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\LeaveStaffView;

use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\StaffView\StaffViews;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Leaving the staff view, from the shop (spec §1.11). While the view holds the shop's request is a
 * guest's, so this is a guest's action - on nothing but the pass this browser carries, which is the
 * only one it can name. Not audited, as signing out is not.
 */
final readonly class LeaveStaffViewHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_VIEW_LEAVE;

    public function __construct(
        private Authorizer $authorizer,
        private StaffViews $views,
    ) {}

    public function handle(LeaveStaffView $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->views->endHere();
    }
}
