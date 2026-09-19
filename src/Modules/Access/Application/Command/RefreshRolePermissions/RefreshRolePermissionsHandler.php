<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RefreshRolePermissions;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Allowed to whoever may edit the role: the same level rule, and covering every holder's stores.
 * Changes no data, so nothing is audited.
 */
final readonly class RefreshRolePermissionsHandler
{
    public const string PERMISSION = AccessPermissions::ROLE_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private RoleRepository $roles,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
    ) {}

    public function handle(RefreshRolePermissions $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $author = $this->rules->author();

        $role = $this->roles->byId($command->roleId) ?? throw new RoleNotFound($command->roleId);

        if ($role->level() === RoleLevel::Admin && ! $author->isUnlimited()) {
            throw new SuperAdminOnly($role->id());
        }

        $holders = $this->assignments->holdersOf($role->id());
        $this->rules->requireCoversHolders($author, $holders);
        $this->grants->refresh(...array_map(fn (RoleAssignment $holder): string => $holder->staffId(), $holders));
    }
}
