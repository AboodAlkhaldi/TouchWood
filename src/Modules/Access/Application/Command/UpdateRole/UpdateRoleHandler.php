<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateRole;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Audit\RoleAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\ValueObject\RoleKind;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class UpdateRoleHandler
{
    public const string PERMISSION = AccessPermissions::ROLE_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private RoleRepository $roles,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    public function handle(UpdateRole $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $author = $this->rules->author();

        $this->db->transaction(function () use ($command, $author): void {
            $role = $this->roles->byId($command->roleId) ?? throw new RoleNotFound($command->roleId);

            if ($role->kind() !== RoleKind::Saved) {
                throw new InvalidAccessAttribute('role', "a personal role is changed from its staff member's page");
            }

            if ($role->level() === RoleLevel::Admin && ! $author->isUnlimited()) {
                throw new SuperAdminOnly($role->id());
            }

            $holders = $this->assignments->holdersOf($role->id());
            $this->rules->requireCoversHolders($author, $holders);

            $nameBefore = $role->name();
            $permissionsBefore = $role->permissions();

            if ($command->nameAr !== null || $command->nameEn !== null) {
                $role->rename(RoleName::of($command->nameAr ?? $nameBefore->ar, $command->nameEn ?? $nameBefore->en));
            }

            if ($command->permissions !== null) {
                $role->changePermissions($command->permissions);
            }

            $changed = $role->pullChanges();

            if ($changed === []) {
                return;
            }

            if (in_array('name', $changed, true)) {
                $taken = $this->roles->savedNameInUse($role->name(), $role->id());

                if ($taken !== null) {
                    throw new RoleNameTaken($taken);
                }
            }

            // The author holds every action of the role, in every store it reaches for each holder.
            $this->rules->requireGrantable($author, $role->level(), $role->permissions());

            foreach ($holders as $holder) {
                $before = clone $holder;
                $removed = $holder->keepExceptionsFor($role->permissions());
                $this->rules->requireCovers($author, $role->permissions(), $holder);

                if ($removed !== []) {
                    $this->assignments->save($holder);
                    $this->platform->recordAudit(RoleAudit::assignmentChanged($before, $holder));
                }
            }

            $this->roles->update($role);
            $this->platform->recordAudit(RoleAudit::updated($role, $nameBefore, $role->level(), $permissionsBefore, $changed));
            $this->grants->refresh(...array_map(fn (RoleAssignment $holder): string => $holder->staffId(), $holders));
        });
    }
}
