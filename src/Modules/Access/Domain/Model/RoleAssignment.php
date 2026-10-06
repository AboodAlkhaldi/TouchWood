<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;
use Modules\Access\Domain\Exception\ActionStoresBeyondReach;
use Modules\Access\Domain\ValueObject\StoreChoice;

/**
 * A staff member's one role, and the stores its actions reach (Access spec §1.5, owner's
 * decision 2026-09-19): one store row for every action, and any single action may have its own
 * stores for this person (an exception).
 *
 * The row - "Where It Reaches" - bounds every action (amendment 59, owner 2026-10-04): an
 * exception lies inside it, and one equal to it says nothing more and is not kept. So a row of one
 * store leaves no room for any exception.
 *
 * That each exception's action is in the role and is a per-store action is checked by the handler,
 * which knows the role and the permission catalog.
 */
final class RoleAssignment
{
    /**
     * @param  array<string, StoreChoice>  $exceptions  action => its own stores
     */
    private function __construct(
        private readonly string $staffId,
        private string $roleId,
        private StoreChoice $stores,
        private array $exceptions,
        private ?string $assignedBy,
        private DateTimeImmutable $assignedAt,
    ) {
        ksort($this->exceptions);
    }

    /**
     * @param  array<string, StoreChoice>  $exceptions
     * @param  string|null  $assignedBy  the staff member who assigned it; null for the system
     *
     * @throws ActionStoresBeyondReach
     */
    public static function assign(string $staffId, string $roleId, StoreChoice $stores, array $exceptions, ?string $assignedBy, DateTimeImmutable $at): self
    {
        return new self($staffId, $roleId, $stores, self::within($stores, $exceptions), $assignedBy, $at);
    }

    /**
     * As stored. A row whose exceptions reached outside it cannot be stored any more, and the
     * migration of amendment 59 stopped on any there were.
     *
     * @param  array<string, StoreChoice>  $exceptions
     */
    public static function reconstitute(string $staffId, string $roleId, StoreChoice $stores, array $exceptions, ?string $assignedBy, DateTimeImmutable $assignedAt): self
    {
        return new self($staffId, $roleId, $stores, $exceptions, $assignedBy, $assignedAt);
    }

    /**
     * @param  array<string, StoreChoice>  $exceptions
     *
     * @throws ActionStoresBeyondReach
     */
    public function reassign(string $roleId, StoreChoice $stores, array $exceptions, ?string $assignedBy, DateTimeImmutable $at): void
    {
        $exceptions = self::within($stores, $exceptions);
        ksort($exceptions);
        $this->roleId = $roleId;
        $this->stores = $stores;
        $this->exceptions = $exceptions;
        $this->assignedBy = $assignedBy;
        $this->assignedAt = $at;
    }

    /**
     * An action's stores: its exception's, otherwise the store row.
     */
    public function storesFor(string $permission): StoreChoice
    {
        return $this->exceptions[$permission] ?? $this->stores;
    }

    /**
     * The staff member's stores, which decide who may see and manage them: the store row alone,
     * every exception lying inside it (amendment 59, replacing amendment 9's union).
     */
    public function staffStores(): StoreChoice
    {
        return $this->stores;
    }

    /**
     * Keeps only the exceptions of actions still in the role: removing an action from a role
     * removes its exceptions (spec §5.4).
     *
     * @param  list<string>  $permissions  the role's actions now
     * @return list<string> the actions whose exception was removed
     */
    public function keepExceptionsFor(array $permissions): array
    {
        $removed = array_values(array_diff(array_keys($this->exceptions), $permissions));

        foreach ($removed as $permission) {
            unset($this->exceptions[$permission]);
        }

        return $removed;
    }

    /**
     * The exceptions the row allows: each inside it, and none equal to it. Public so that a handler
     * can refuse them from the request alone, before anything is looked up - so the refusal never
     * answers differently for one staff member than for another.
     *
     * @param  array<string, StoreChoice>  $exceptions
     * @return array<string, StoreChoice>
     *
     * @throws ActionStoresBeyondReach
     */
    public static function within(StoreChoice $row, array $exceptions): array
    {
        $kept = [];

        foreach ($exceptions as $permission => $stores) {
            if ($stores->equals($row)) {
                continue;
            }

            if (! $row->includes($stores)) {
                throw new ActionStoresBeyondReach($permission);
            }

            $kept[$permission] = $stores;
        }

        return $kept;
    }

    public function staffId(): string
    {
        return $this->staffId;
    }

    public function roleId(): string
    {
        return $this->roleId;
    }

    public function stores(): StoreChoice
    {
        return $this->stores;
    }

    /**
     * @return array<string, StoreChoice>
     */
    public function exceptions(): array
    {
        return $this->exceptions;
    }

    public function assignedBy(): ?string
    {
        return $this->assignedBy;
    }

    public function assignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }
}
