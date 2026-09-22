<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\CurrentStore;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * Which store the admin panel opens in for the person acting now (stage 2b, P3).
 *
 * The remembered store **if it is still one of theirs**, otherwise their first store by the store's
 * own position — and the screen is told when it fell back, so it can say so: "You no longer have
 * access to that store — showing <the store's name>." No store is named here: a store's name comes
 * from its row, never from code (handoff §2.2).
 *
 * The remembered value is never trusted on the way out. An admin may have narrowed the person's
 * role since they chose it, and a preference must never decide what anyone may see.
 */
final readonly class CurrentStoreForStaff
{
    public function __construct(
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private PlatformApi $platform,
    ) {}

    public function forCurrentStaff(): CurrentStoreDto
    {
        $staffId = $this->rules->currentStaffId();
        $theirs = $this->storesOf($staffId);

        if ($theirs === []) {
            return new CurrentStoreDto(null, false);
        }

        $remembered = $this->staff->currentStore($staffId);

        if ($remembered !== null && in_array($remembered, $theirs, true)) {
            return new CurrentStoreDto($remembered, false);
        }

        // Falling back: their first store by position. It is only announced when they had chosen
        // one and lost it — a person who has never chosen has nothing to be told about.
        return new CurrentStoreDto($theirs[0], $remembered !== null);
    }

    /**
     * Their stores, in the stores' own order. A Super Admin covers every store without a role
     * saying so; anyone else covers their assignment's, an exception's stores included.
     *
     * @return list<string>
     */
    private function storesOf(string $staffId): array
    {
        $all = array_map(static fn (object $store): string => $store->id, $this->platform->stores());

        if ($this->rules->author()->isUnlimited()) {
            return $all;
        }

        $choice = $this->assignments->byStaff($staffId)?->staffStores();

        if ($choice === null) {
            return [];
        }

        return array_values(array_filter($all, static fn (string $id): bool => $choice->covers(StoreId::fromString($id))));
    }
}
