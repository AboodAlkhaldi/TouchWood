<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\StaffActions;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

/**
 * What the person acting now may do to one staff member (frontend.md §3.3, C2).
 *
 * The screen asks once and offers only what comes back, so nobody is shown a button that refuses
 * them when pressed. The answer is Access's, not the screen's, and each handler asks again anyway.
 *
 * Two rules sit above every permission here (access.md §1.4, §1.6):
 *
 *   - **A Super Admin is untouchable from the panel.** They are made and removed by console command
 *     only, so a hijacked admin session cannot mint or remove one.
 *   - **Nobody manages themselves.** Their own account is theirs to change, on their own account
 *     screen, and not through the staff list.
 */
final readonly class StaffActionsForReader
{
    public function __construct(
        private ActorContext $actors,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
    ) {}

    public function forStaff(string $staffId): StaffActionsDto
    {
        $actor = $this->actors->current();
        $target = $this->staff->byId($staffId);

        if ($actor->type !== ActorType::Staff || $actor->id === null || $target === null) {
            return StaffActionsDto::none();
        }

        if ($target->isSuperAdmin() || $target->id() === $actor->id) {
            return StaffActionsDto::none();
        }

        $stores = $this->assignments->byStaff($staffId)?->staffStores();
        $may = fn (string $permission): bool => $this->rules->mayStillManage($actor->id ?? '', $permission, $target, $stores);

        $update = $may(AccessPermissions::STAFF_UPDATE);
        $assign = $may(AccessPermissions::STAFF_ASSIGN_ROLE);
        $disable = $may(AccessPermissions::STAFF_DISABLE);
        $invite = $may(AccessPermissions::STAFF_INVITE);

        $invited = $target->status() === StaffStatus::Invited;
        $active = $target->status() === StaffStatus::Active;

        return new StaffActionsDto(
            mayEditProfile: $update,
            mayChangeEmail: $update,
            mayChangeRole: $assign,
            // Disabling ends their sessions at once; enabling is the same permission the other way.
            mayDisable: $disable && $active,
            mayEnable: $disable && $target->status() === StaffStatus::Disabled,
            // An invitation can only be resent or cancelled while it is still open.
            mayResendInvitation: $invite && $invited,
            mayCancelInvitation: $invite && $invited,
            mayRefresh: $assign,
        );
    }
}
