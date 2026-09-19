<?php

declare(strict_types=1);

namespace Modules\Access\Application\Permission;

use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;

/**
 * The permissions every module declares while the application boots.
 */
final class InMemoryPermissionCatalog implements PermissionCatalog
{
    /** "{module}.{resource}.{action}"; a resource may have parts, as in "platform.media.variants.generate". */
    private const string NAME = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}\z/';

    /** @var array<string, PermissionDefinitionDto> */
    private array $definitions = [];

    public function declare(string $module, PermissionDefinitionDto ...$permissions): void
    {
        foreach ($permissions as $permission) {
            $name = $permission->name;

            if (preg_match(self::NAME, $name) !== 1) {
                throw new InvalidPermissionDefinition("The permission \"{$name}\" must look like \"{module}.{resource}.{action}\" in lowercase.");
            }

            if (! str_starts_with($name, $module.'.')) {
                throw new InvalidPermissionDefinition("The {$module} module cannot declare \"{$name}\": its permissions must start with \"{$module}.\".");
            }

            if (isset($this->definitions[$name])) {
                throw new InvalidPermissionDefinition("The permission \"{$name}\" is already declared.");
            }

            // Reserved means "Super Admins only, never in a role". An automatic permission is held by
            // everyone of its kind, so it cannot also be reserved.
            if ($permission->reserved && $permission->audience !== PermissionAudience::Role) {
                throw new InvalidPermissionDefinition("The permission \"{$name}\" cannot be both reserved and held automatically by everyone of its kind.");
            }

            $this->definitions[$name] = $permission;
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
            fn (PermissionDefinitionDto $permission): bool => $permission->audience === PermissionAudience::Role && ! $permission->reserved,
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
}
