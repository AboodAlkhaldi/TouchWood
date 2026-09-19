<?php

declare(strict_types=1);

namespace Modules\Access\Application\Authorization;

use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\Exception\AdminOnlyPermission;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\PermissionEscalation;
use Modules\Access\Domain\Exception\ReservedPermission;
use Modules\Access\Domain\Exception\StaffNotEditable;
use Modules\Access\Domain\Exception\UnknownPermission;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\StoreChoice;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * The rules for changing roles and who holds them (Access spec §1.5, owner's decisions 2026-09-18
 * and 2026-09-19):
 *
 * - nobody grants more than they hold: a role holds only actions its author holds, and an action
 *   reaches only stores its author covers for it — only the system and Super Admins are unlimited;
 * - management actions go only into admin roles;
 * - only a Super Admin manages admins; nobody changes their own role;
 * - an admin manages a staff member only when covering all of that person's stores.
 */
final readonly class GrantRules
{
    public function __construct(
        private ActorContext $actors,
        private GrantsReader $grants,
        private InMemoryPermissionCatalog $catalog,
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /**
     * Who is acting. Call after the handler's authorize(): anyone else was refused already.
     *
     * A console command is unlimited. A queued job acts as the system on behalf of whoever queued
     * it, so it grants only what that person holds (spec §1.5).
     */
    public function author(): Author
    {
        $actor = $this->actors->current();

        if ($actor->type === ActorType::System) {
            if ($actor->requestedBy === null) {
                return Author::unlimited();
            }

            $actor = $actor->requestedBy;
        }

        $staff = $actor->type === ActorType::Staff ? $this->grants->forStaff((string) $actor->id) : null;

        if ($staff === null || ! $staff->isActive()) {
            throw new Unauthorized(AccessPermissions::ROLE_MANAGE);
        }

        return $staff->superAdmin ? Author::unlimited($staff->staffId) : Author::staff($staff);
    }

    /**
     * The staff member acting on their own account. The system, even in a job, has no own account.
     */
    public function currentStaffId(): string
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            throw new Unauthorized(AccessPermissions::OWN_ACCOUNT_UPDATE);
        }

        return $actor->id;
    }

    /**
     * Only the server's console: never a person, not even a Super Admin, and never a job queued on
     * someone's behalf — so a hijacked panel session cannot make or remove a Super Admin (spec §1.6).
     */
    public function requireConsole(string $permission): void
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::System || $actor->requestedBy !== null) {
            throw new Unauthorized($permission);
        }
    }

    /**
     * Each action may go into a role of this level, and the author holds it.
     *
     * @param  list<string>  $permissions
     */
    public function requireGrantable(Author $author, RoleLevel $level, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $definition = $this->catalog->definition($permission);

            if ($definition === null || $definition->audience !== PermissionAudience::Role) {
                throw new UnknownPermission($permission);
            }

            if ($definition->reserved) {
                throw new ReservedPermission($permission);
            }

            if ($level === RoleLevel::Staff && in_array($permission, AccessPermissions::adminOnly(), true)) {
                throw new AdminOnlyPermission($permission);
            }

            if (! $author->holds($permission)) {
                throw new PermissionEscalation($permission);
            }
        }
    }

    /**
     * Every exception is for a per-store action of the role; a store-free action takes no store
     * choice (owner's decision, 2026-09-19).
     *
     * @param  list<string>  $permissions  the role's actions
     * @param  array<string, StoreChoice>  $exceptions
     */
    public function requireValidExceptions(array $permissions, array $exceptions): void
    {
        foreach (array_keys($exceptions) as $permission) {
            if (! in_array($permission, $permissions, true)) {
                throw new InvalidAccessAttribute('exceptions', "\"{$permission}\" is not an action of the role");
            }

            if ($this->catalog->definition($permission)?->kind !== PermissionKind::PerStore) {
                throw new InvalidAccessAttribute('exceptions', "\"{$permission}\" is store-free and takes no stores of its own");
            }
        }
    }

    /**
     * Every store in the choice exists.
     */
    public function requireKnownStores(StoreChoice $stores): void
    {
        $known = array_map(fn (StoreDto $store): string => $store->id, $this->platform->stores());

        foreach ($stores->storeIds() as $storeId) {
            if (! in_array($storeId, $known, true)) {
                throw new InvalidAccessAttribute('stores', "no store has the id \"{$storeId}\"");
            }
        }
    }

    /**
     * For every action of the role, the author holds it in every store it reaches for this staff
     * member. A name no module declares any more cannot be covered: it grants nothing now, but
     * would come back to life with its module, so a limited author cannot hand it out.
     *
     * @param  list<string>  $permissions  the role's actions
     */
    public function requireCovers(Author $author, array $permissions, RoleAssignment $assignment): void
    {
        if ($author->isUnlimited()) {
            return;
        }

        foreach ($permissions as $permission) {
            $definition = $this->catalog->definition($permission) ?? throw new UnknownPermission($permission);
            $held = $author->storesFor($permission);
            $covered = $held !== null
                && ($definition->kind === PermissionKind::Global || $held->includes($assignment->storesFor($permission)));

            if (! $covered) {
                throw new PermissionEscalation($permission);
            }
        }
    }

    /**
     * Not a Super Admin (only the console manages them), not yourself, and not an admin unless you
     * are a Super Admin.
     */
    public function requireManageable(Author $author, StaffUser $target, ?StaffGrants $targetGrants): void
    {
        if ($target->isSuperAdmin()) {
            throw new StaffNotEditable($target->id(), StaffNotEditable::SUPER_ADMIN);
        }

        if ($author->staffId === $target->id()) {
            throw new StaffNotEditable($target->id(), StaffNotEditable::YOURSELF);
        }

        if ($targetGrants?->isAdmin() === true && ! $author->isUnlimited()) {
            throw new StaffNotEditable($target->id(), StaffNotEditable::ADMIN);
        }
    }

    /**
     * The checks that prove the author holds a per-store management action in every one of these
     * stores: all stores needs "All stores". Handlers pass each to the authorizer themselves.
     *
     * @return list<PermissionScope> empty when there is no store at all (see requireSomewhere())
     */
    public function scopesFor(?StoreChoice $stores): array
    {
        if ($stores === null) {
            return [];
        }

        if ($stores->isAllStores()) {
            return [PermissionScope::allStores()];
        }

        return array_map(PermissionScope::store(...), $stores->stores() ?? []);
    }

    /**
     * The role screens are read by whoever manages roles or assigns them (spec §3.2, amendment 8).
     */
    public function requireRoleReader(): void
    {
        if ($this->authorizer->storesWith(AccessPermissions::ROLE_MANAGE) === []
            && $this->authorizer->storesWith(AccessPermissions::STAFF_ASSIGN_ROLE) === []) {
            throw new Unauthorized(AccessPermissions::ROLE_MANAGE);
        }
    }

    public function mayManageRoles(): bool
    {
        return $this->authorizer->storesWith(AccessPermissions::ROLE_MANAGE) !== [];
    }

    /**
     * A staff member with no stores at all (no role yet) is managed by anyone who holds the
     * management action in some store.
     */
    public function requireSomewhere(string $permission): void
    {
        if ($this->authorizer->storesWith($permission) === []) {
            throw new Unauthorized($permission);
        }
    }

    /**
     * Editing, deleting or refreshing a saved role changes the access of everyone who holds it:
     * only an author who covers all the stores of every holder may do it (owner's decisions,
     * 2026-09-19).
     *
     * @param  list<RoleAssignment>  $holders
     */
    public function requireCoversHolders(Author $author, array $holders): void
    {
        foreach ($holders as $holder) {
            if (! $this->covers($author, $holder->staffStores())) {
                throw new Unauthorized(AccessPermissions::STAFF_ASSIGN_ROLE);
            }
        }
    }

    /**
     * An admin's reach over a staff member: the author holds the management action that changes
     * people's access — assigning roles — in all of their stores (owner's decision, 2026-09-19).
     */
    public function covers(Author $author, ?StoreChoice $stores): bool
    {
        if ($author->isUnlimited() || $stores === null) {
            return true;
        }

        return $author->storesFor(AccessPermissions::STAFF_ASSIGN_ROLE)?->includes($stores) === true;
    }

    /**
     * The names a module still declares. A name left behind by a module that is switched off stays
     * where it is (it grants nothing) but is never copied into a new role.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public function declaredOnly(array $permissions): array
    {
        return array_values(array_filter($permissions, fn (string $permission): bool => $this->catalog->definition($permission) !== null));
    }
}
