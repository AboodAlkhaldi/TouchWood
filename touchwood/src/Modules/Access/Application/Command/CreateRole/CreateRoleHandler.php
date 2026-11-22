<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CreateRole;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Audit\RoleAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class CreateRoleHandler
{
    public const string PERMISSION = AccessPermissions::ROLE_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private RoleRepository $roles,
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the new role's id
     */
    public function handle(CreateRole $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $author = $this->rules->author();

        if ($command->level === RoleLevel::Admin && ! $author->isUnlimited()) {
            throw new SuperAdminOnly;
        }

        $role = Role::saved($this->roles->nextId(), $command->level, RoleName::of($command->nameAr, $command->nameEn), $command->permissions);
        $this->rules->requireGrantable($author, $role->level(), $role->permissions());

        return $this->db->transaction(function () use ($role): string {
            $taken = $this->roles->savedNameInUse($role->name());

            if ($taken !== null) {
                throw new RoleNameTaken($taken);
            }

            $this->roles->add($role);
            $this->platform->recordAudit(RoleAudit::created($role));

            return $role->id();
        }, 3);
    }
}
