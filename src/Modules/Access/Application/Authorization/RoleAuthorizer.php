<?php

declare(strict_types=1);

namespace Modules\Access\Application\Authorization;

use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\ValueObject\StoreChoice;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionKind;
use Shared\Application\Actor;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The real permission check (Access spec §1.5, §2.5), replacing Platform's interim one. Deny by
 * default:
 *
 * - a Super Admin holds everything, everywhere, reserved permissions included;
 * - other staff hold the automatic staff permissions, and their role's actions in each action's
 *   stores — only while their account is active;
 * - a guest holds the automatic guest permissions;
 * - customers get theirs with customer accounts (step 4); integrations hold none yet;
 * - the system holds everything: Access's ActorContext never reports it for a web request — only
 *   for the console, and for a queued job, which grants only what its requester holds (GrantRules).
 */
final readonly class RoleAuthorizer implements Authorizer
{
    public function __construct(
        private ActorContext $actors,
        private InMemoryPermissionCatalog $catalog,
        private GrantsReader $grants,
    ) {}

    public function authorize(string $permission, PermissionScope $scope): void
    {
        $definition = $this->definition($permission);

        if ($definition->kind === PermissionKind::Global && ! $scope->isGlobal()) {
            throw new InvalidPermissionCheck("\"{$permission}\" is store-free: check it with PermissionScope::global(), not {$scope->describe()}.");
        }

        if ($definition->kind === PermissionKind::PerStore && $scope->isGlobal()) {
            throw new InvalidPermissionCheck("\"{$permission}\" is per store: check it against one store or every store, not globally.");
        }

        if (! $this->allows($this->actors->current(), $definition, $scope)) {
            throw new Unauthorized($permission);
        }
    }

    public function storesWith(string $permission): ?array
    {
        $definition = $this->definition($permission);
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff) {
            return $this->allowsWithoutRole($actor, $definition) ? null : [];
        }

        $staff = $this->grants->forStaff((string) $actor->id);

        if ($staff === null || ! $staff->isActive()) {
            return [];
        }

        if ($staff->superAdmin || $definition->audience === PermissionAudience::EveryStaff) {
            return null;
        }

        $stores = $this->roleStores($staff, $definition);

        if ($stores === null) {
            return [];
        }

        // A store-free action is held in full, whatever the person's stores.
        return $definition->kind === PermissionKind::Global ? null : $stores->stores();
    }

    private function allows(Actor $actor, PermissionDefinitionDto $definition, PermissionScope $scope): bool
    {
        if ($actor->type !== ActorType::Staff) {
            return $this->allowsWithoutRole($actor, $definition);
        }

        $staff = $this->grants->forStaff((string) $actor->id);

        if ($staff === null || ! $staff->isActive()) {
            return false;
        }

        if ($staff->superAdmin || $definition->audience === PermissionAudience::EveryStaff) {
            return true;
        }

        $stores = $this->roleStores($staff, $definition);

        return match (true) {
            $stores === null => false,
            $definition->kind === PermissionKind::Global => true,
            $scope->requiresAllStores() => $stores->isAllStores(),
            default => $stores->covers($scope->storeId() ?? throw new InvalidPermissionCheck('A store check without a store.')),
        };
    }

    /**
     * The system, guests, customers and integrations: no role, only their automatic permissions.
     */
    private function allowsWithoutRole(Actor $actor, PermissionDefinitionDto $definition): bool
    {
        return match ($actor->type) {
            ActorType::System => true,
            ActorType::Guest => $definition->audience === PermissionAudience::EveryGuest,
            // Their own account only (spec §1.5): a customer holds no role. A blocked account never
            // signs in, and a session open when it is blocked ends at once (spec §1.8).
            ActorType::Customer => $definition->audience === PermissionAudience::EveryCustomer,
            // Integrations hold nothing until their keys are built; a staff actor has grants.
            ActorType::Integration, ActorType::Staff => false,
        };
    }

    /**
     * The stores a role action reaches for this person, or null when they do not hold it. Whatever
     * a role contains, reserved permissions belong to Super Admins only, and the management actions
     * to admins only (checked again here in case one reached a staff role another way).
     */
    private function roleStores(StaffGrants $staff, PermissionDefinitionDto $definition): ?StoreChoice
    {
        if ($definition->audience !== PermissionAudience::Role || $definition->reserved) {
            return null;
        }

        if (! $staff->isAdmin() && in_array($definition->name, AccessPermissions::adminOnly(), true)) {
            return null;
        }

        return $staff->storesFor($definition->name);
    }

    private function definition(string $permission): PermissionDefinitionDto
    {
        return $this->catalog->definition($permission)
            ?? throw new InvalidPermissionCheck("\"{$permission}\" is checked but no module declares it.");
    }
}
