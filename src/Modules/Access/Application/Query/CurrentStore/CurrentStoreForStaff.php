<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\CurrentStore;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
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
        private ActorContext $actors,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private PlatformApi $platform,
    ) {}

    /**
     * Null when nobody is signed in: the admin shell asks this on every page, the sign-in page
     * included, and "nobody, so no store" is the honest answer there rather than a refusal.
     */
    public function forCurrentStaff(): ?CurrentStoreDto
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            return null;
        }

        $staffId = $actor->id;
        $unlimited = $this->rules->author()->isUnlimited();
        [$theirs, $off] = $this->storesOf($staffId, $unlimited);

        if ($theirs === []) {
            return new CurrentStoreDto(null, false);
        }

        // An off store is shown to whoever covers it, marked Off, but only a Super Admin works in
        // one - to prepare it before it opens (platform.md §1.6, access.md amendment 58(a); owner,
        // 2026-10-03). A staff member whose only stores are off still signs in, and works in no
        // store until one is on again (amendment 53).
        $choosable = $unlimited ? $theirs : array_values(array_diff($theirs, $off));
        $remembered = $this->staff->currentStore($staffId);

        if ($remembered !== null && in_array($remembered, $choosable, true)) {
            return new CurrentStoreDto($remembered, false, $theirs, $off, $unlimited);
        }

        // Falling back: their first store that is **on**, by position - a Super Admin included. An
        // off store is one somebody chooses to prepare; nobody lands in one by default (the review of
        // the foundation, 2026-10-03). It is only announced when they had chosen one and lost it — a
        // person who has never chosen has nothing to be told about — and it says whether the store
        // was switched off or taken away from them.
        $on = array_values(array_diff($theirs, $off));

        if ($on === []) {
            return new CurrentStoreDto(null, false, $theirs, $off, $unlimited);
        }

        return new CurrentStoreDto(
            $on[0],
            $remembered !== null,
            $theirs,
            $off,
            $unlimited,
            $remembered !== null && in_array($remembered, $off, true),
        );
    }

    /**
     * Their stores, on and off, in the stores' own order, and which of them are off. A Super Admin
     * covers every store without a role saying so; anyone else covers their assignment's, an
     * exception's stores included.
     *
     * @return array{0: list<string>, 1: list<string>} their stores, and the ones that are off
     */
    private function storesOf(string $staffId, bool $unlimited): array
    {
        $all = $this->platform->allStores();

        if (! $unlimited) {
            $choice = $this->assignments->byStaff($staffId)?->staffStores();

            if ($choice === null) {
                return [[], []];
            }

            $all = array_values(array_filter($all, static fn (StoreDto $store): bool => $choice->covers(StoreId::fromString($store->id))));
        }

        return [
            array_map(static fn (StoreDto $store): string => $store->id, $all),
            array_values(array_map(static fn (StoreDto $store): string => $store->id, array_filter($all, static fn (StoreDto $store): bool => ! $store->isActive))),
        ];
    }
}
