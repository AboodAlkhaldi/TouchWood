<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CloneRole;

use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Audit\RoleAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\ValueObject\RoleKind;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class CloneRoleHandler
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
    public function handle(CloneRole $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $author = $this->rules->author();
        $name = RoleName::of($command->nameAr, $command->nameEn);

        return $this->db->transaction(function () use ($command, $author, $name): string {
            $source = $this->roles->byId($command->sourceRoleId);

            // A personal role belongs to one staff member: only saved roles are cloned.
            if ($source === null || $source->kind() !== RoleKind::Saved) {
                throw new RoleNotFound($command->sourceRoleId);
            }

            if ($source->level() === RoleLevel::Admin && ! $author->isUnlimited()) {
                throw new SuperAdminOnly($source->id());
            }

            // A name left behind by a switched-off module is not copied: it grants nothing.
            $permissions = $this->rules->declaredOnly($source->permissions());
            $this->rules->requireGrantable($author, $source->level(), $permissions);

            $taken = $this->roles->savedNameInUse($name);

            if ($taken !== null) {
                throw new RoleNameTaken($taken);
            }

            $clone = Role::saved($this->roles->nextId(), $source->level(), $name, $permissions);
            $this->roles->add($clone);
            $this->platform->recordAudit(RoleAudit::created($clone, clonedFrom: $source->id()));

            return $clone->id();
        });
    }
}
