<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Home;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\HomeCards;
use Modules\Platform\Public\Dto\HomeCardDto;
use Modules\Platform\Public\Dto\HomeCardView;
use Modules\Platform\Public\Dto\HomeScope;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * The admin home's cards, collected from the modules' service providers at boot (platform.md
 * §2.6), the way the admin menu is.
 *
 * Platform keeps them because it sits below every module. It never reaches into Access to know who
 * may see what: it asks the Shared Authorizer, which Access implements.
 */
final class InMemoryHomeCards implements HomeCards
{
    /** @var list<HomeCardDto> */
    private array $cards = [];

    /**
     * The container, not the Authorizer: this list lives for the whole application, while the
     * Authorizer is bound scoped, to the person acting now (the project's rule, as the menu's).
     */
    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(HomeCardDto ...$cards): void
    {
        foreach ($cards as $card) {
            $named = [...$card->permissions(), ...$card->storeFree];

            if ($named === [] || in_array('', $named, true)) {
                throw new LogicException("The home card \"{$card->module}.{$card->key}\" names no permission: it would show to nobody while looking guarded.");
            }

            if (! is_subclass_of($card->card, HomeCard::class)) {
                throw new LogicException("The home card \"{$card->module}.{$card->key}\" is drawn by \"{$card->card}\", which does not implement ".HomeCard::class.'.');
            }

            foreach ($this->cards as $registered) {
                if ($registered->module === $card->module && $registered->key === $card->key) {
                    throw new LogicException("The home card \"{$card->module}.{$card->key}\" is registered twice.");
                }
            }

            $this->cards[] = $card;
        }
    }

    /**
     * Every card registered, for the tests that hold the registrations to the permissions'
     * declared kinds.
     *
     * @return list<HomeCardDto>
     */
    public function all(): array
    {
        return $this->cards;
    }

    public function forCurrentActor(?string $storeWorkedIn, bool $allStores): array
    {
        $store = $storeWorkedIn === null ? null : StoreId::fromString($storeWorkedIn);
        $scope = $allStores ? HomeScope::allStores() : ($store === null ? null : HomeScope::store($store));

        if ($scope === null) {
            return [];
        }

        $authorizer = $this->container->make(Authorizer::class);
        $cards = $this->cards;
        usort($cards, fn (HomeCardDto $a, HomeCardDto $b): int => [$a->position, $a->module, $a->key] <=> [$b->position, $b->module, $b->key]);
        $views = [];

        foreach ($cards as $card) {
            if (! $this->mayShow($authorizer, $card, $allStores ? null : $store)) {
                continue;
            }

            $drawer = $this->container->make($card->card);

            // Checked when the card was registered; resolved from the container, so asked again.
            if (! $drawer instanceof HomeCard) {
                throw new LogicException("\"{$card->card}\" does not implement ".HomeCard::class.'.');
            }

            $data = $drawer->data($scope);

            if ($data !== null) {
                $views[] = new HomeCardView($card, $data);
            }
        }

        return $views;
    }

    /**
     * Only a per-store action held for every store offers All Stores: a store-free one held at all
     * reads as every store too, and would offer it to an admin of one store (the review of P5).
     */
    public function offersAllStores(): bool
    {
        $authorizer = $this->container->make(Authorizer::class);

        foreach ($this->cards as $card) {
            foreach ($card->permissions() as $permission) {
                if ($authorizer->storesWith($permission) === null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  StoreId|null  $store  the store chosen on Home; null asks for every store
     */
    private function mayShow(Authorizer $authorizer, HomeCardDto $card, ?StoreId $store): bool
    {
        foreach ($card->permissions() as $permission) {
            $stores = $authorizer->storesWith($permission);

            // Every store: what the All Stores scope needs, and enough for any one store.
            if ($stores === null) {
                return true;
            }

            if ($store !== null && in_array($store->value, array_map(static fn (StoreId $held): string => $held->value, $stores), true)) {
                return true;
            }
        }

        // Belonging to no store, these read the same in either scope.
        foreach ($card->storeFree as $permission) {
            if ($authorizer->storesWith($permission) !== []) {
                return true;
            }
        }

        return false;
    }
}
