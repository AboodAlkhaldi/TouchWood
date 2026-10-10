<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\StoresForStaff;

use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Domain\ValueObject\StoreId;

/**
 * The stores of the staff member acting now (access.md amendment 64): what Home's switcher and View
 * Store's menu offer, now that the panel has no store "worked in" (the owner, 2026-10-06).
 *
 * A Super Admin's are every store, on and off - an off store is theirs to prepare before it opens
 * (platform.md §1.6). Anyone else's are the stores their assignment covers - its store row, which
 * every exception lies inside (amendment 59) - that are on: an off store is as if it were never
 * there to them. No store is named here: a store's name comes from its row (handoff §2.2).
 *
 * The store row is read from the person's cached permissions, which hold it, not from their
 * assignment (amendment 65): that was four more queries, with a row lock, on every admin page.
 */
final readonly class StoresForStaff
{
    public function __construct(
        private ActorContext $actors,
        private GrantRules $rules,
        private GrantsReader $grants,
        private PlatformApi $platform,
    ) {}

    /**
     * Empty for anyone not signed in to the panel: the admin shell asks on every page, the sign-in
     * page included, and "nobody, so no store" is the honest answer there.
     *
     * @return list<StoreDto> in the stores' own order
     */
    public function forCurrentStaff(): array
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Staff || $actor->id === null) {
            return [];
        }

        if ($this->rules->author()->isUnlimited()) {
            return $this->platform->allStores();
        }

        $choice = $this->grants->forStaff($actor->id)?->stores;

        if ($choice === null) {
            return [];
        }

        return array_values(array_filter(
            $this->platform->allStores(),
            static fn (StoreDto $store): bool => $store->isActive && $choice->covers(StoreId::fromString($store->id)),
        ));
    }

    /**
     * One of them, by its code: the store a menu or a switcher sent.
     */
    public function byCode(string $code): ?StoreDto
    {
        $asked = strtolower(trim($code));

        foreach ($this->forCurrentStaff() as $store) {
            if ($store->code === $asked) {
                return $store;
            }
        }

        return null;
    }
}
