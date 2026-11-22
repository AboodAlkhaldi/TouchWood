<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Shipping\Application\Query\CarrierRow;
use Modules\Shipping\Application\Query\ListCarriers\CarrierCountry;
use Modules\Shipping\Application\Query\ListCarriers\ListCarriers;
use Modules\Shipping\Application\Query\ListCarriers\ListCarriersHandler;
use Modules\Shipping\Application\Query\ListPriceGroups\ListPriceGroups;
use Modules\Shipping\Application\Query\ListPriceGroups\ListPriceGroupsHandler;
use Modules\Shipping\Application\Query\ListPriceGroups\PriceGroupRow;
use Modules\Shipping\Application\Query\ListUncoveredPlaces\ListUncoveredPlaces;
use Modules\Shipping\Application\Query\ListUncoveredPlaces\ListUncoveredPlacesHandler;
use Modules\Shipping\Application\Query\ListUncoveredPlaces\UncoveredCountry;
use Modules\Shipping\Application\Query\PlaceRow;
use Modules\Shipping\Application\Query\ViewCarrier\ViewCarrier;
use Modules\Shipping\Application\Query\ViewCarrier\ViewCarrierHandler;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Application\ShippingSettings;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * The carriers screens' data (shipping.md §4.1 parts 1 and 2), built from steps 3 and 4's queries - which
 * authorize - the logos' thumbnails from Platform's media in one read (confirmed by the backend session,
 * 2026-10-29: `CarrierRow` stays as it is), the store-wide free-above amount from Platform's setting, and the
 * price dialog's place picker from Platform's own picker reads, one level at a time (R3).
 */
final readonly class CarrierPages
{
    /**
     * What opens the carriers screens of a store: viewing them (§4.1 part 1, C1 "The menu"). Managing
     * alone does not - the reads authorize `carriers.view`, a plain role permission ticked per role
     * (§9.4 #1), so a role that manages carriers ticks it too.
     */
    public const array JOBS = [ShippingPermissions::CARRIERS_VIEW];

    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private PlatformApi $platform,
    ) {}

    /**
     * C1, with part 2's store-wide amount and each country's places no carrier serves (R12, R13).
     *
     * @throws Unauthorized
     */
    public function list(?string $storeCode, ListCarriersHandler $carriers, ListUncoveredPlacesHandler $uncovered): CarriersPage
    {
        [$store, $offered] = $this->store($storeCode);
        $locale = $this->locale();
        $countries = $carriers->handle(new ListCarriers($store->id, $locale));
        $logos = $this->logos(array_merge(...array_map(static fn (CarrierCountry $country): array => $country->carriers, $countries)));
        $unserved = [];

        foreach ($uncovered->handle(new ListUncoveredPlaces($store->id, $locale)) as $country) {
            /** @var UncoveredCountry $country */
            $unserved[$country->code] = array_map(fn (PlaceRow $row): PricePlaceData => $this->place($row, $locale), $country->places);
        }

        return new CarriersPage(
            array_map(fn (CarrierCountry $country): CarrierCountryData => new CarrierCountryData(
                $country->code,
                $country->name->in($locale),
                $country->own,
                array_map(fn (CarrierRow $row): CarrierData => $this->data($row, $logos, $store, $locale), $country->carriers),
                $unserved[$country->code] ?? [],
            ), $countries),
            $this->may($store, ShippingPermissions::CARRIERS_MANAGE),
            $store->code,
            $store->name->in($locale),
            $store->isActive,
            array_map(fn (StoreDto $each): StoreOptionData => new StoreOptionData($each->code, $each->name->in($locale), $each->isActive), $offered),
            $this->storeFreeAbove($store, $locale),
            $this->may($store, ShippingPermissions::PRICES_MANAGE),
            $store->currencySymbol($locale),
            $store->currencyExponent,
        );
    }

    /**
     * C3: the carrier, in the store the address names, with its prices (part 2). A carrier of another store
     * is not found there.
     *
     * @throws Unauthorized
     */
    public function carrier(?string $storeCode, string $carrierId, ViewCarrierHandler $view, ListPriceGroupsHandler $prices): CarrierPage
    {
        [$store] = $this->store($storeCode);
        $locale = $this->locale();
        $row = $view->handle(new ViewCarrier($store->id, strtolower($carrierId)));
        $groups = $prices->handle(new ListPriceGroups($store->id, $row->id, $locale));
        $country = null;

        foreach ($this->platform->addressCountries($locale) as $each) {
            if ($each->code === $row->countryCode) {
                $country = $each->name->in($locale);
                break;
            }
        }

        return new CarrierPage(
            $this->data($row, $this->logos([$row]), $store, $locale),
            $country ?? $row->countryCode,
            $this->may($store, ShippingPermissions::CARRIERS_MANAGE),
            $store->code,
            $store->name->in($locale),
            $store->isActive,
            array_map(fn (PriceGroupRow $group): PriceGroupData => new PriceGroupData(
                $group->id,
                MoneyData::of(Money::of($group->amountMinor, $store->currencyCode), $store, $locale),
                array_map(fn (PlaceRow $place): PricePlaceData => $this->place($place, $locale), $group->places),
            ), $groups->groups),
            $groups->version,
            $this->storeFreeAbove($store, $locale),
            $this->may($store, ShippingPermissions::PRICES_MANAGE),
            $store->currencySymbol($locale),
            $store->currencyExponent,
        );
    }

    /**
     * One level of the price dialog's place picker (R3): the carrier's country's regions, a region's cities,
     * or a city's districts - Platform's picker reads, nothing retired. The carrier is read first, so a reader
     * who may not see it is refused, and a region or a city of another country lists nothing to pick: the
     * save refuses it anyway (`PlaceNotInCountry`).
     *
     * @return list<PickPlaceData>
     *
     * @throws Unauthorized
     */
    public function pick(?string $storeCode, string $carrierId, ?string $region, ?string $city, ViewCarrierHandler $view): array
    {
        [$store] = $this->store($storeCode);
        $locale = $this->locale();
        $other = $locale === 'ar' ? 'en' : 'ar';
        $country = $view->handle(new ViewCarrier($store->id, strtolower($carrierId)))->countryCode;
        $places = match (true) {
            $city !== null => $this->platform->districts(strtolower($city), $locale),
            $region !== null => $this->platform->cities(strtolower($region), $locale),
            default => $this->platform->regions($country, $locale),
        };

        return array_values(array_map(
            static fn (PlaceDto $place): PickPlaceData => new PickPlaceData($place->id, $place->name->in($locale), $place->name->in($other)),
            array_filter($places, static fn (PlaceDto $place): bool => $place->countryCode === $country),
        ));
    }

    /**
     * The store shown - the one asked for, else the first that is on among those where the reader may
     * open the carriers screens - and the stores offered. A Super Admin with every store off still
     * opens one.
     *
     * @return array{0: StoreDto, 1: list<StoreDto>}
     *
     * @throws Unauthorized
     */
    private function store(?string $code): array
    {
        $offered = $this->choices->forJobs(...self::JOBS);
        $chosen = null;

        foreach ($offered as $store) {
            if ($code === null ? $store->isActive : strtolower($store->code) === strtolower(trim($code))) {
                $chosen = $store;
                break;
            }
        }

        $chosen ??= $code === null ? ($offered[0] ?? null) : null;

        return [$chosen ?? throw new Unauthorized(ShippingPermissions::CARRIERS_VIEW), $offered];
    }

    /**
     * Whether the reader may do this in the store: carriers' buttons (Q12), prices' (R14). Asked of the
     * stores offered, not `chosen()`, which refuses a store it does not offer rather than saying no.
     */
    private function may(StoreDto $store, string $permission): bool
    {
        foreach ($this->choices->forJobs($permission) as $each) {
            if ($each->id === $store->id) {
                return true;
            }
        }

        return false;
    }

    /** The store-wide free-above amount (R12): null while it is off (0). */
    private function storeFreeAbove(StoreDto $store, string $locale): ?MoneyData
    {
        $minor = $this->platform->setting(ShippingSettings::FREE_ABOVE_ALL, StoreId::fromString($store->id))->int();

        return $minor > 0 ? MoneyData::of(Money::of($minor, $store->currencyCode), $store, $locale) : null;
    }

    /** @param  array<string, string|null>  $logos */
    private function data(CarrierRow $row, array $logos, StoreDto $store, string $locale): CarrierData
    {
        return new CarrierData(
            $row->id,
            $row->countryCode,
            $row->name->ar,
            $row->name->en,
            $row->logoMediaId,
            $row->logoMediaId === null ? null : ($logos[strtolower($row->logoMediaId)] ?? null),
            $row->phone,
            $row->website,
            $row->trackingPattern,
            $row->active,
            $row->freeAboveMinor === null ? null : MoneyData::of(Money::of($row->freeAboveMinor, $store->currencyCode), $store, $locale),
        );
    }

    /** A place by name in the page's language, with what it is under: a district's city, a city's region. */
    private function place(PlaceRow $row, string $locale): PricePlaceData
    {
        $under = $row->cityName ?? $row->regionName;

        return new PricePlaceData($row->id, $row->kind->value, $row->name->in($locale), $under?->in($locale), $row->retired);
    }

    /**
     * Every logo's thumbnail in one read.
     *
     * @param  list<CarrierRow>  $rows
     * @return array<string, string|null>
     */
    private function logos(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (CarrierRow $row): ?string => $row->logoMediaId, $rows), static fn (?string $id): bool => $id !== null && $id !== '')));
        $logos = [];

        foreach ($ids === [] ? [] : $this->platform->mediaUrlsOf($ids) as $id => $urls) {
            $logos[strtolower((string) $id)] = self::thumb($urls);
        }

        return $logos;
    }

    private static function thumb(MediaUrlsDto $urls): ?string
    {
        $sizes = $urls->variants[MediaSize::Thumb->slug()] ?? [];

        foreach ([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg] as $format) {
            $url = $sizes[$format->extension()] ?? null;

            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }

    /** The page's language: Arabic unless English. */
    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
