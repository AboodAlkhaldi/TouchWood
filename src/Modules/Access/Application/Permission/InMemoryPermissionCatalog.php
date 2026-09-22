<?php

declare(strict_types=1);

namespace Modules\Access\Application\Permission;

use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;

/**
 * The permissions every module declares while the application boots, and the renames and
 * removals that the next migrate carries into the roles (Access spec §1.5, amendment 3).
 */
final class InMemoryPermissionCatalog implements PermissionCatalog
{
    /** "{module}.{resource}.{action}"; a resource may have parts, as in "platform.media.variants.generate". */
    private const string NAME = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}\z/';

    /** The width of the permission columns in the access schema. */
    public const int MAX_NAME_LENGTH = 128;

    /** @var array<string, PermissionDefinitionDto> */
    private array $definitions = [];

    /** @var array<string, string> old name => new name */
    private array $renames = [];

    /** @var array<string, true> */
    private array $removals = [];

    public function declare(string $module, PermissionDefinitionDto ...$permissions): void
    {
        foreach ($permissions as $permission) {
            $name = $permission->name;
            $this->requireWellFormed($module, $name);

            if (isset($this->definitions[$name])) {
                throw new InvalidPermissionDefinition("The permission \"{$name}\" is already declared.");
            }

            // Reserved means "Super Admins only, never in a role". An automatic permission is held by
            // everyone of its kind, so it cannot also be reserved.
            if ($permission->reserved && $permission->audience !== PermissionAudience::Role) {
                throw new InvalidPermissionDefinition("The permission \"{$name}\" cannot be both reserved and held automatically by everyone of its kind.");
            }

            // The role editor and the menu show an action under its business area (stage 2b, P2).
            // Only an action a role can hold is ever shown, so only those carry a group.
            $offered = $permission->audience === PermissionAudience::Role && ! $permission->reserved;

            if ($offered && $permission->group === null) {
                throw new InvalidPermissionDefinition("The permission \"{$name}\" is offered in the role editor, so it needs a group.");
            }

            if (! $offered && $permission->group !== null) {
                throw new InvalidPermissionDefinition("The permission \"{$name}\" is never offered in the role editor, so it must have no group.");
            }

            $this->definitions[$name] = $permission;
        }
    }

    public function renamed(string $module, string $from, string $to): void
    {
        $this->requireWellFormed($module, $from);
        $this->requireWellFormed($module, $to);

        if ($from === $to) {
            throw new InvalidPermissionDefinition("\"{$from}\" cannot be renamed to itself.");
        }

        if (isset($this->renames[$from])) {
            throw new InvalidPermissionDefinition("\"{$from}\" is already renamed to \"{$this->renames[$from]}\".");
        }

        $this->renames[$from] = $to;
    }

    public function removed(string $module, string ...$names): void
    {
        foreach ($names as $name) {
            $this->requireWellFormed($module, $name);
            $this->removals[$name] = true;
        }
    }

    /**
     * Checks what can only be checked once every module has declared its permissions: a rename's
     * old name is gone and its new name is a permission a role can hold; a removed name is gone.
     * Called when the application has booted.
     *
     * @throws InvalidPermissionDefinition
     */
    public function verify(): void
    {
        // Two permissions merged into one would have to merge two people's store choices for it;
        // rename one and remove the other instead.
        foreach (array_count_values($this->renames) as $to => $count) {
            if ($count > 1) {
                throw new InvalidPermissionDefinition("\"{$to}\" is the new name of {$count} renamed permissions; rename one and remove the others.");
            }
        }

        foreach ($this->renames as $from => $to) {
            if (isset($this->definitions[$from])) {
                throw new InvalidPermissionDefinition("\"{$from}\" is renamed to \"{$to}\" but is still declared.");
            }

            $target = $this->definitions[$to] ?? null;

            if ($target === null || $target->audience !== PermissionAudience::Role || $target->reserved) {
                throw new InvalidPermissionDefinition("\"{$from}\" is renamed to \"{$to}\", which is not a declared permission a role can hold.");
            }

            if (isset($this->removals[$from])) {
                throw new InvalidPermissionDefinition("\"{$from}\" is both renamed and removed.");
            }
        }

        foreach (array_keys($this->removals) as $name) {
            if (isset($this->definitions[$name])) {
                throw new InvalidPermissionDefinition("\"{$name}\" is removed but is still declared.");
            }
        }
    }

    public function definition(string $name): ?PermissionDefinitionDto
    {
        return $this->definitions[$name] ?? null;
    }

    /**
     * @return list<PermissionDefinitionDto> in the order they were declared
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * What the role editor offers: role permissions that are not reserved.
     *
     * @return list<PermissionDefinitionDto>
     */
    public function assignable(): array
    {
        return array_values(array_filter(
            $this->definitions,
            fn (PermissionDefinitionDto $permission): bool => $this->isAssignable($permission),
        ));
    }

    /**
     * @return list<PermissionDefinitionDto> the permissions everyone of this kind holds automatically
     */
    public function automatic(PermissionAudience $audience): array
    {
        return array_values(array_filter(
            $this->definitions,
            fn (PermissionDefinitionDto $permission): bool => $permission->audience === $audience && $audience !== PermissionAudience::Role,
        ));
    }

    /**
     * @return array<string, string> old name => new name
     */
    public function renames(): array
    {
        return $this->renames;
    }

    /**
     * @return list<string>
     */
    public function removals(): array
    {
        return array_keys($this->removals);
    }

    private function isAssignable(PermissionDefinitionDto $permission): bool
    {
        return $permission->audience === PermissionAudience::Role && ! $permission->reserved;
    }

    private function requireWellFormed(string $module, string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidPermissionDefinition("The permission \"{$name}\" must look like \"{module}.{resource}.{action}\" in lowercase.");
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidPermissionDefinition("The permission \"{$name}\" is longer than ".self::MAX_NAME_LENGTH.' characters.');
        }

        if (! str_starts_with($name, $module.'.')) {
            throw new InvalidPermissionDefinition("The {$module} module cannot declare \"{$name}\": its permissions must start with \"{$module}.\".");
        }
    }
}
