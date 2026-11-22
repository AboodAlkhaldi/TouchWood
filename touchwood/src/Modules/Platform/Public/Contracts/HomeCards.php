<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeCardDto;
use Modules\Platform\Public\Dto\HomeCardView;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Enums\HomePeriod;

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
     * Read over `$period` (dashboard.md §3.3), up to now.
     *
     * @return list<HomeCardView>
     */
    public function forCurrentActor(?string $storeWorkedIn, bool $allStores, HomePeriod $period = HomePeriod::OPENS_ON): array;

    /**
     * The cards the person acting now may see in the scope, in their order, without what they show -
     * offered by the rule of `forCurrentActor()`, so the home can keep what each answered (dashboard.md
     * §7, D11) and ask `read()` only for the rest.
     *
     * @return list<HomeCardDto>
     */
    public function offeredTo(?string $storeWorkedIn, bool $allStores): array;

    /**
     * What one card shows for the scope, or null when it has nothing to say there. Ask it only for a card
     * `offeredTo()` named for the same scope: it does not ask again who may see it.
     */
    public function read(HomeCardDto $card, HomeScope $scope): ?HomeCardData;

    /**
     * Whether the reader holds any card's per-store action for every store - the scope switch is
     * offered only then. A store-free action never counts: held at all, it reads as every store.
     */
    public function offersAllStores(): bool;
}
