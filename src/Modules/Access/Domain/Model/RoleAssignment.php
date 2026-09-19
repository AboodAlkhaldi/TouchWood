<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Model;

use DateTimeImmutable;
use Modules\Access\Domain\ValueObject\StoreChoice;

/**
 * A staff member's one role, and the stores its actions reach (Access spec §1.5, owner's
 * decision 2026-09-19): one store row for every action, and any single action may have its own
 * stores for this person (an exception).
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
     */
    public static function assign(string $staffId, string $roleId, StoreChoice $stores, array $exceptions, ?string $assignedBy, DateTimeImmutable $at): self
    {
        return new self($staffId, $roleId, $stores, $exceptions, $assignedBy, $at);
    }

    /**
     * @param  array<string, StoreChoice>  $exceptions
     */
    public static function reconstitute(string $staffId, string $roleId, StoreChoice $stores, array $exceptions, ?string $assignedBy, DateTimeImmutable $assignedAt): self
    {
        return new self($staffId, $roleId, $stores, $exceptions, $assignedBy, $assignedAt);
    }

    /**
     * @param  array<string, StoreChoice>  $exceptions
     */
    public function reassign(string $roleId, StoreChoice $stores, array $exceptions, ?string $assignedBy, DateTimeImmutable $at): void
    {
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
     * The staff member's stores, which decide who may manage them: the store row plus every store
     * an exception adds (owner's decision, 2026-09-19).
     */
    public function staffStores(): StoreChoice
    {
        $stores = $this->stores;

        foreach ($this->exceptions as $exception) {
            $stores = $stores->union($exception);
        }

        return $stores;
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
