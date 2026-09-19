<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\DeleteRole;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Application\Audit\RoleAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\RoleInUse;
use Modules\Access\Domain\Exception\RoleNotFound;
use Modules\Access\Domain\Exception\SuperAdminOnly;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\ValueObject\RoleKind;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class DeleteRoleHandler
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

    public function handle(DeleteRole $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $author = $this->rules->author();

        $this->db->transaction(function () use ($command, $author): void {
            $role = $this->roles->byId($command->roleId) ?? throw new RoleNotFound($command->roleId);

            if ($role->kind() !== RoleKind::Saved) {
                throw new InvalidAccessAttribute('role', 'a personal role ends when its staff member moves to a saved role');
            }

            if ($role->level() === RoleLevel::Admin && ! $author->isUnlimited()) {
                throw new SuperAdminOnly($role->id());
            }

            $holders = $this->assignments->holdersOf($role->id());
            $this->rules->requireCoversHolders($author, $holders);
            $holderIds = array_map(fn (RoleAssignment $holder): string => $holder->staffId(), $holders);

            if ($holders !== [] && $command->replacementRoleId === null) {
                throw new RoleInUse($role->id(), $holderIds);
            }

            $replacementId = null;

            if ($holders !== []) {
                $replacement = $this->roles->byId((string) $command->replacementRoleId)
                    ?? throw new RoleNotFound((string) $command->replacementRoleId);

                // A replacement of the same level: staff never become admins through a delete.
                if ($replacement->kind() !== RoleKind::Saved || $replacement->level() !== $role->level() || $replacement->id() === $role->id()) {
                    throw new InvalidAccessAttribute('replacement', 'pick another saved role of the same level');
                }

                $replacementId = $replacement->id();
                $now = CarbonImmutable::now();

                foreach ($holders as $holder) {
                    $before = clone $holder;
                    $holder->keepExceptionsFor($replacement->permissions());
                    $holder->reassign($replacement->id(), $holder->stores(), $holder->exceptions(), $author->staffId, $now);
                    $this->rules->requireCovers($author, $replacement->permissions(), $holder);
                    $this->assignments->save($holder);
                    $this->platform->recordAudit(RoleAudit::assignmentChanged($before, $holder));
                }
            }

            $this->roles->delete($role->id());
            $this->platform->recordAudit(RoleAudit::deleted($role, $replacementId, $holderIds));
            $this->grants->refresh(...$holderIds);
        });
    }
}
