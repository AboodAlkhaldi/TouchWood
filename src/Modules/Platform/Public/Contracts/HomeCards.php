<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\HomeCardDto;
use Modules\Platform\Public\Dto\HomeCardView;

/**
 * The admin home's cards (platform.md §2.6; frontend.md §2.2), registered by every module in its
 * service provider as menu entries are. Platform keeps the list because it sits below everything,
 * and asks the Shared Authorizer - never Access - who may see what.
 */
interface HomeCards
{
    /**
     * @throws \LogicException for two cards with the same module and key, a card class that is not a
     *                         HomeCard, or a card naming no permission, per-store or store-free
     */
    public function register(HomeCardDto ...$cards): void;

    /**
     * The cards the person acting now may see, in their order, each with what it shows.
     *
     * One store ($allStores false): a card's per-store action held in the store chosen on Home -
     * none when no store is. All Stores: held for every store. A card's store-free action shows it
     * in either. A Super Admin holds every permission in every store, so sees every card in either.
     *
     * @return list<HomeCardView>
     */
    public function forCurrentActor(?string $storeWorkedIn, bool $allStores): array;

    /**
     * Whether the reader holds any card's per-store action for every store - the scope switch is
     * offered only then. A store-free action never counts: held at all, it reads as every store.
     */
    public function offersAllStores(): bool;
}
