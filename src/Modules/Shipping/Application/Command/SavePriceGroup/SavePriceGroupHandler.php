<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SavePriceGroup;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Shipping\Application\Carrier\CarrierChange;
use Modules\Shipping\Application\Price\PriceAudit;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\AmountAlreadyUsed;
use Modules\Shipping\Domain\Exception\CarrierNotFound;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Domain\Exception\PlaceAlreadyPriced;
use Modules\Shipping\Domain\Exception\PlaceNotInCountry;
use Modules\Shipping\Domain\Exception\PriceGroupNotFound;
use Modules\Shipping\Domain\Exception\PricesChanged;
use Modules\Shipping\Domain\Model\Carrier;
use Modules\Shipping\Domain\Model\PriceGroup;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Domain\Repository\PriceGroupRepository;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Saves one price group of a carrier under the rules of shipping.md §1.4, all checked inside the store's
 * carriers' lock so two staff saving at once never break them between them:
 *
 * - rule 3: a place is in one group of the carrier - another group's place is refused, naming it and its
 *   amount, unless it is moved here ("Move here"), which takes it out of there in this save;
 * - rule 4: a new group with an amount another group has is refused, naming that group; a group whose
 *   amount is changed to another group's is merged into that one;
 * - a group left with no place - this one, or one its places moved out of - is deleted.
 */
final readonly class SavePriceGroupHandler
{
    public const string PERMISSION = ShippingPermissions::PRICES_MANAGE;

    /** The most places one save may name - a whole country's cities fit (the largest list has 4,354). */
    public const int MOST_PLACES = 10000;

    public const int MOST_AMOUNT = 100000000;

    public function __construct(
        private CarrierChange $change,
        private CarrierRepository $carriers,
        private PriceGroupRepository $groups,
        private PlatformApi $platform,
    ) {}

    /**
     * @return string the saved group's id - the group merged into, when the amount met another's (a
     *                group emptied by its own save is deleted, and the id it had is returned)
     *
     * @throws AmountAlreadyUsed|CarrierNotFound|InvalidShippingInput|PlaceAlreadyPriced|PlaceNotInCountry|PriceGroupNotFound|PricesChanged|Unauthorized
     */
    public function handle(SavePriceGroup $command): string
    {
        $store = $this->change->authorize(self::PERMISSION, $command->storeId);

        if ($command->amountMinor < 0 || $command->amountMinor > self::MOST_AMOUNT) {
            throw new InvalidShippingInput('amount', 'between 0 and '.self::MOST_AMOUNT);
        }

        $placeIds = self::ids($command->placeIds);
        $moveIds = self::ids($command->moveIds);

        if (count($placeIds) > self::MOST_PLACES || count($moveIds) > self::MOST_PLACES) {
            throw new InvalidShippingInput('places', 'at most '.self::MOST_PLACES);
        }

        return $this->change->run($store->id, function () use ($store, $command, $placeIds, $moveIds): array {
            $carrier = $this->carriers->byId($store->id, $command->carrierId) ?? throw new CarrierNotFound($command->carrierId);
            $groups = [];

            foreach ($this->groups->ofCarrier($carrier->id) as $group) {
                $groups[$group->id] = $group;
            }

            // Another save came first: told to reload, never written over (§8 #3).
            if (PriceGroup::versionOf(array_values($groups)) !== $command->seenVersion) {
                throw new PricesChanged($carrier->id);
            }

            $groupId = $command->groupId === null ? null : strtolower(trim($command->groupId));
            $target = $groupId === null ? null : ($groups[$groupId] ?? throw new PriceGroupNotFound($groupId));
            $before = array_map(static fn (PriceGroup $group): PriceGroup => clone $group, $groups);

            if ($command->allUnpriced) {
                $placeIds = array_values(array_unique([...$placeIds, ...$this->unpriced($carrier, $groups, $placeIds)]));

                if (count($placeIds) > self::MOST_PLACES) {
                    throw new InvalidShippingInput('places', 'at most '.self::MOST_PLACES);
                }
            }

            $places = $this->places($carrier, $placeIds, $target);

            if ($places === [] && $target === null) {
                throw new InvalidShippingInput('places', 'at least one place');
            }

            // Rule 4: an amount another group has - refused for a new group, merged for a changed one.
            $other = null;

            foreach ($groups as $group) {
                if ($group->id !== $target?->id && $group->amountMinor() === $command->amountMinor) {
                    $other = $group;
                }
            }

            if ($target === null && $other !== null) {
                throw new AmountAlreadyUsed($other->id, self::amount($other->amountMinor(), $store));
            }

            // Rule 3: another group's place is refused unless it moves here - or is in the group this one
            // merges into, where it ends up anyway.
            $owners = [];

            foreach ($groups as $group) {
                foreach ($group->placeIds() as $placeId) {
                    $owners[$placeId] = $group;
                }
            }

            $moving = array_flip($moveIds);

            foreach ($placeIds as $placeId) {
                $owner = $owners[$placeId] ?? null;

                if ($owner === null || $owner === $target || $owner === $other) {
                    continue;
                }

                if (! isset($moving[$placeId])) {
                    throw new PlaceAlreadyPriced($placeId, $places[$placeId]->name->ar, $places[$placeId]->name->en, $owner->id, self::amount($owner->amountMinor(), $store));
                }

                $owner->remove($placeId);
            }

            if ($target === null) {
                $target = PriceGroup::of($this->groups->nextId(), $carrier->id, $store->id, $command->amountMinor, $placeIds);
                $groups[$target->id] = $target;
            } elseif ($other !== null) {
                // Merged: the other group takes this one's places, and this one goes.
                $other->setPlaces([...$other->placeIds(), ...$placeIds]);
                unset($groups[$target->id]);
                $target = $other;
            } else {
                $target->changeAmount($command->amountMinor);
                $target->setPlaces($placeIds);
            }

            $kept = array_values(array_filter($groups, static fn (PriceGroup $group): bool => ! $group->isEmpty()));
            $this->groups->saveCarrier($carrier->id, $kept);

            return [$target->id, PriceAudit::entries($carrier, $store, $before, $kept, $this->platform)];
        });
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $ids)));
    }

    /**
     * The places, each Platform's, of the carrier's country and in use - a retired place may stay only in
     * the group that already has it (§9.3 #4: its price still applies to the addresses that name it).
     *
     * @param  list<string>  $placeIds
     * @return array<string, PlaceDto>
     */
    private function places(Carrier $carrier, array $placeIds, ?PriceGroup $target): array
    {
        foreach ($placeIds as $placeId) {
            if (! Ulids::valid($placeId)) {
                throw new PlaceNotInCountry($placeId, $carrier->countryCode);
            }
        }

        $found = $this->platform->place($placeIds);

        foreach ($placeIds as $placeId) {
            $place = $found[$placeId] ?? null;

            if ($place === null || $place->countryCode !== $carrier->countryCode || ($place->retired && ! (bool) $target?->has($placeId))) {
                throw new PlaceNotInCountry($placeId, $carrier->countryCode);
            }
        }

        return $found;
    }

    /**
     * The country's "Select all" (gap 4.2): every city in use not yet priced in this carrier and not under
     * a region it prices - or, where the country has no city in use, every region not yet priced. The
     * places of this very save count as priced: a region picked with Select all keeps its cities out.
     *
     * @param  array<string, PriceGroup>  $groups
     * @param  list<string>  $picked
     * @return list<string>
     */
    private function unpriced(Carrier $carrier, array $groups, array $picked): array
    {
        $priced = array_fill_keys($picked, true);

        foreach ($groups as $group) {
            foreach ($group->placeIds() as $placeId) {
                $priced[$placeId] = true;
            }
        }

        $regions = [];
        $cities = [];

        foreach ($this->platform->placesOf($carrier->countryCode) as $place) {
            if ($place->kind === PlaceKind::Region) {
                $regions[] = $place;
            } elseif ($place->kind === PlaceKind::City) {
                $cities[] = $place;
            }
        }

        if ($cities === []) {
            return array_values(array_map(static fn (PlaceDto $region): string => $region->id, array_filter($regions, static fn (PlaceDto $region): bool => ! isset($priced[$region->id]))));
        }

        return array_values(array_map(static fn (PlaceDto $city): string => $city->id, array_filter(
            $cities,
            static fn (PlaceDto $city): bool => ! isset($priced[$city->id]) && ! isset($priced[(string) $city->regionId]),
        )));
    }

    private static function amount(int $amountMinor, StoreDto $store): string
    {
        return Money::of($amountMinor, $store->currencyCode)->format($store->currencyExponent);
    }
}
