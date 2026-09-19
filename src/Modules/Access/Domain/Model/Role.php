<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\ValueObject\RoleKind;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;

/**
 * A named set of actions (Access spec §1.5). A saved role is shared by everyone who holds it; a
 * personal role belongs to one staff member. The role holds only the actions: the stores are
 * chosen per staff member, on their RoleAssignment.
 *
 * Whether each action exists and may go in this role (declared, not reserved, not a management
 * action in a staff role) is checked by the handler against the permission catalog. The kind never
 * changes, and a saved role's level never changes; a personal role is edited in place, level
 * included, because each staff member has at most one.
 */
final class Role
{
    /** @var list<string> */
    private array $changed = [];

    /** @var list<string> sorted, without repeats */
    private array $permissions;

    /**
     * @param  list<string>  $permissions
     */
    private function __construct(
        private readonly string $id,
        private readonly RoleKind $kind,
        private RoleLevel $level,
        private readonly ?string $personalTo,
        private RoleName $name,
        array $permissions,
    ) {
        if (($kind === RoleKind::Personal) !== ($personalTo !== null)) {
            throw new InvalidAccessAttribute('role', 'a personal role belongs to exactly one staff member, a saved role to nobody');
        }

        $this->permissions = self::normalise($permissions);
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function saved(string $id, RoleLevel $level, RoleName $name, array $permissions): self
    {
        self::requireAction($permissions);

        return new self($id, RoleKind::Saved, $level, null, $name, $permissions);
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function personal(string $id, RoleLevel $level, string $staffId, RoleName $name, array $permissions): self
    {
        self::requireAction($permissions);

        return new self($id, RoleKind::Personal, $level, $staffId, $name, $permissions);
    }

    /**
     * Rebuilds a role from storage. Not a creation: nothing about it is new.
     *
     * @param  list<string>  $permissions
     */
    public static function reconstitute(string $id, RoleKind $kind, RoleLevel $level, ?string $personalTo, RoleName $name, array $permissions): self
    {
        return new self($id, $kind, $level, $personalTo, $name, $permissions);
    }

    public function rename(RoleName $name): void
    {
        if (! $this->name->equals($name)) {
            $this->name = $name;
            $this->markChanged('name');
        }
    }

    /**
     * Only a personal role: a saved role's holders chose it as an admin or a staff role.
     */
    public function changeLevel(RoleLevel $level): void
    {
        if ($this->kind !== RoleKind::Personal) {
            throw new InvalidAccessAttribute('level', "a saved role's level never changes");
        }

        if ($this->level !== $level) {
            $this->level = $level;
            $this->markChanged('level');
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    public function changePermissions(array $permissions): void
    {
        self::requireAction($permissions);
        $permissions = self::normalise($permissions);

        if ($permissions !== $this->permissions) {
            $this->permissions = $permissions;
            $this->markChanged('permissions');
        }
    }

    /**
     * The attributes changed since the role was loaded, cleared once read.
     *
     * @return list<string>
     */
    public function pullChanges(): array
    {
        [$changed, $this->changed] = [$this->changed, []];

        return $changed;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): RoleKind
    {
        return $this->kind;
    }

    public function level(): RoleLevel
    {
        return $this->level;
    }

    public function personalTo(): ?string
    {
        return $this->personalTo;
    }

    public function name(): RoleName
    {
        return $this->name;
    }

    /**
     * @return list<string> sorted
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function holds(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private static function normalise(array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }

    /**
     * A role is given at least one action (owner's decision, 2026-09-19). Checked when a role is
     * created or changed, not when it is loaded: a module removing its last action can leave a
     * stored role empty, and that role must still open so an admin can fix it.
     *
     * @param  list<string>  $permissions
     */
    private static function requireAction(array $permissions): void
    {
        if ($permissions === []) {
            throw new InvalidAccessAttribute('permissions', 'a role needs at least one action');
        }
    }

    private function markChanged(string $attribute): void
    {
        if (! in_array($attribute, $this->changed, true)) {
            $this->changed[] = $attribute;
        }
    }
}
