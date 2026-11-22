<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListStores;

use Modules\Platform\Application\Query\ListAddressCountries\AddressCountryRow;
use Modules\Platform\Application\Query\ListAddressCountries\ListAddressCountriesHandler;
use Modules\Platform\Application\Query\NameOrder;
use Modules\Platform\Application\Query\PlacesPanelReader;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\ValueObject\IsoCountries;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;

/**
 * The stores this person may see, and which of them they may change (frontend.md §3.5, E1).
 *
 * "Each screen shows only the stores in the person's scope": somebody who manages one store opens
 * this screen and sees that store, not every store with most of them refusing. The screen is told
 * nothing about permissions and decides nothing; it is handed the stores and a flag per store.
 *
 * Viewing and changing are asked separately, because they are separate permissions and a person may
 * well hold the first without the second - a manager who reads every store's settings and edits
 * none of them.
 *
 * **An off store is listed only to whoever may switch stores** — a Super Admin, through the reserved
 * `platform.store.switch` — so it can be turned back on (platform.md §1.6, §3; owner, 2026-10-01).
 * To anyone else it is as if it were never there.
 */
final readonly class ListStoresHandler
{
    public const string PERMISSION = PlatformPermissions::STORE_VIEW;

    public function __construct(
        private Authorizer $authorizer,
        private StoreDirectory $directory,
        private PlacesPanelReader $places,
        private ListAddressCountriesHandler $countries,
    ) {}

    /**
     * @return list<StoreSummary>
     *
     * @throws Unauthorized when they may not see any store at all
     */
    public function handle(ListStores $query): array
    {
        $visible = $this->authorizer->storesWith(self::PERMISSION);

        // Nowhere at all: the screen does not exist for them, and Access's own refusal says so
        // rather than an empty list that reads as "there are no stores".
        if ($visible === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $editable = $this->authorizer->storesWith(PlatformPermissions::STORE_UPDATE);
        $maySwitch = $this->maySwitch();

        // Null from Access means every store, now and for any store added later.
        $maySee = $visible === null ? null : array_map(static fn ($store): string => $store->value, $visible);
        $mayEdit = $editable === null ? null : array_map(static fn ($store): string => $store->value, $editable);

        $summaries = [];
        $shipsTo = $this->places->shipsTo();

        foreach ($this->directory->stores() as $store) {
            if ($maySee !== null && ! in_array($store->id, $maySee, true)) {
                continue;
            }

            if (! $store->isActive && ! $maySwitch) {
                continue;
            }

            $summaries[] = $this->summary($store, $mayEdit === null || in_array($store->id, $mayEdit, true), $maySwitch, $query->locale, $shipsTo[$store->id] ?? []);
        }

        return $summaries;
    }

    /**
     * Whether the person acting may turn stores on and off: a store-free, reserved permission, held
     * everywhere or nowhere.
     */
    public function maySwitch(): bool
    {
        return $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== [];
    }

    /**
     * Whether the person acting may change a store's ship-to countries (platform.md §4.3): the
     * reserved, store-free `platform.store.countries` - a Super Admin. Offering it is not allowing
     * it: AddStoreCountry and RemoveStoreCountry ask again.
     */
    public function mayChangeCountries(): bool
    {
        return $this->authorizer->storesWith(PlatformPermissions::STORE_COUNTRIES) !== [];
    }

    /**
     * Add Country's choices on a store's card (platform.md §4.3 B4): the address countries in use,
     * each with the store that ships there and whether it has its place list - the screen marks those
     * out of reach, and leaves out what the card's store already ships to. Nobody else is offered
     * any: empty unless mayChangeCountries().
     *
     * @return list<AddressCountryRow>
     */
    public function countriesToAdd(string $locale): array
    {
        if (! $this->mayChangeCountries()) {
            return [];
        }

        return NameOrder::sort(
            array_values(array_filter($this->countries->rows(), static fn (AddressCountryRow $row): bool => ! $row->retired)),
            $locale,
            static fn (AddressCountryRow $row): string => $row->name->in($locale),
        );
    }

    /**
     * Whether the person acting may open a store from the stores screen (platform.md §9.7 #3): the
     * reserved, store-free `platform.store.create` - a Super Admin. Offering it is not allowing it:
     * CreateStore asks again.
     */
    public function mayCreate(): bool
    {
        return $this->authorizer->storesWith(PlatformPermissions::STORE_CREATE) !== [];
    }

    /**
     * @param  list<string>  $shipsTo  its ship-to countries, by code
     */
    private function summary(StoreDto $store, bool $editable, bool $maySwitch, string $locale, array $shipsTo): StoreSummary
    {
        // Its own country first: it is always there, and the rest read as what it adds.
        $others = array_values(array_filter($shipsTo, static fn (string $code): bool => $code !== $store->countryCode));

        return new StoreSummary(
            $store->id,
            $store->code,
            $store->name->ar,
            $store->name->en,
            $store->countryCode,
            $store->currencyCode,
            $store->currencySymbol($locale),
            $store->taxRateBasisPoints,
            $store->timezone,
            $store->position,
            $editable,
            $store->isActive,
            $store->isBase,
            $maySwitch && ! $store->isBase,
            array_map(
                static fn (string $code): ShipsToCountry => new ShipsToCountry($code, new TranslatedTextDto(IsoCountries::name($code, 'ar'), IsoCountries::name($code, 'en'))),
                in_array($store->countryCode, $shipsTo, true) ? [$store->countryCode, ...$others] : $others,
            ),
        );
    }
}
