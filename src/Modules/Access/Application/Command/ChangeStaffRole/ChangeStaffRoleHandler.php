<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeStaffRole;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Audit\RoleAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\RoleKind;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;
use Modules\Access\Domain\ValueObject\StoreChoice;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

/**
 * Access spec §3.2, owner's decisions 2026-09-19: the admin picks a saved role and keeps it as it
 * is, or edits it into the staff member's personal role; then the store row and any action's own
 * stores. Refused for a Super Admin, for yourself, for an admin unless you are a Super Admin, and
 * unless the author holds the management action in every store the staff member has now and will
 * have, and every action they give in every store it reaches.
 */
final readonly class ChangeStaffRoleHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_ASSIGN_ROLE;

    /** Locks are taken roles first, then staff and assignments; a deadlock is retried. */
    private const int ATTEMPTS = 3;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleRepository $roles,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    public function handle(ChangeStaffRole $command): void
    {
        if (($command->savedRoleId === null) === ($command->personalRole === null)) {
            throw new InvalidAccessAttribute('role', 'pick a saved role, or edit it into a personal role');
        }

        $row = StoreChoice::of($command->accessLevel, $command->storeIds);
        $exceptions = ActionStores::toChoices($command->exceptions);

        // Before anything is looked up, so someone without the action learns nothing about ids.
        $this->rules->requireSomewhere(self::PERMISSION);

        $this->db->transaction(function () use ($command, $row, $exceptions): void {
            // Roles are locked before staff and assignments, in every handler, so two changes cannot
            // deadlock.
            $saved = $command->savedRoleId === null ? null : $this->savedRole($command->savedRoleId);
            $personal = $this->roles->personalRoleOf($command->staffId);
            $personalBefore = $personal === null ? null : clone $personal;
            $target = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);
            $current = $this->assignments->byStaff($target->id());

            $role = $saved ?? $this->editPersonal($target->id(), $personal, $command->personalRole);

            $next = RoleAssignment::assign($target->id(), $role->id(), $row, $exceptions, null, CarbonImmutable::now());

            // The management action in every store the staff member has now and will have.
            $stores = $current === null ? $next->staffStores() : $current->staffStores()->union($next->staffStores());

            foreach ($this->rules->scopesFor($stores) as $scope) {
                $this->authorizer->authorize(self::PERMISSION, $scope);
            }

            $author = $this->rules->author();
            $this->rules->requireManageable($author, $target, $this->grants->forStaff($target->id()));

            // A cancelled account is final (amendment 29).
            if ($target->status() === StaffStatus::Cancelled) {
                throw new InvalidStaffStatus($target->status());
            }

            if ($role->level() === RoleLevel::Admin && ! $author->isUnlimited()) {
                throw new SuperAdminOnly($role->kind() === RoleKind::Saved ? $role->id() : null);
            }

            foreach ([$row, ...array_values($exceptions)] as $choice) {
                $this->rules->requireKnownStores($choice);
            }

            if ($role->kind() === RoleKind::Personal) {
                $this->rules->requireGrantable($author, $role->level(), $role->permissions());
            }

            $this->rules->requireValidExceptions($role->permissions(), $exceptions);
            $this->rules->requireCovers($author, $role->permissions(), $next);

            $roleChanges = $role->pullChanges();

            // The same role and stores again: nothing to write or audit.
            if ($roleChanges === [] && $current !== null && $this->same($current, $next)) {
                return;
            }

            $this->writeRole($role, $personalBefore, $roleChanges);

            $before = $current === null ? null : clone $current;
            $assignment = $current ?? $next;
            $assignment->reassign($role->id(), $row, $exceptions, $author->staffId, $next->assignedAt());
            $this->assignments->save($assignment);

            // A personal role nobody holds any more ends (owner's decision, 2026-09-19).
            if ($personalBefore !== null && $role->id() !== $personalBefore->id()) {
                $this->roles->delete($personalBefore->id());
                $this->platform->recordAudit(RoleAudit::deleted($personalBefore, null, []));
            }

            $this->platform->recordAudit(RoleAudit::assignmentChanged($before, $assignment));
            $this->grants->refresh($target->id());
        }, self::ATTEMPTS);
    }

    private function same(RoleAssignment $current, RoleAssignment $next): bool
    {
        if ($current->roleId() !== $next->roleId() || ! $current->stores()->equals($next->stores())
            || array_keys($current->exceptions()) !== array_keys($next->exceptions())) {
            return false;
        }

        foreach ($next->exceptions() as $permission => $stores) {
            if (! $current->storesFor($permission)->equals($stores)) {
                return false;
            }
        }

        return true;
    }

    private function savedRole(string $roleId): Role
    {
        $role = $this->roles->byId($roleId);

        // Someone else's personal role is not offered.
        if ($role === null || $role->kind() !== RoleKind::Saved) {
            throw new RoleNotFound($roleId);
        }

        return $role;
    }

    /**
     * The staff member's personal role, edited in place when they have one: each staff member has
     * at most one.
     */
    private function editPersonal(string $staffId, ?Role $personal, ?PersonalRole $input): Role
    {
        $input ?? throw new InvalidAccessAttribute('role', 'pick a saved role, or edit it into a personal role');
        $name = RoleName::of($input->nameAr, $input->nameEn);

        if ($personal === null) {
            return Role::personal($this->roles->nextId(), $input->level, $staffId, $name, $input->permissions);
        }

        $personal->rename($name);
        $personal->changeLevel($input->level);
        $personal->changePermissions($input->permissions);

        return $personal;
    }

    /**
     * @param  list<string>  $changed  what changed on an existing personal role
     */
    private function writeRole(Role $role, ?Role $personalBefore, array $changed): void
    {
        if ($role->kind() === RoleKind::Saved) {
            return;
        }

        if ($personalBefore === null) {
            $this->roles->add($role);
            $this->platform->recordAudit(RoleAudit::created($role));

            return;
        }

        if ($changed !== []) {
            $this->roles->update($role);
            $this->platform->recordAudit(RoleAudit::updated($role, $personalBefore->name(), $personalBefore->level(), $personalBefore->permissions(), $changed));
        }
    }
}
