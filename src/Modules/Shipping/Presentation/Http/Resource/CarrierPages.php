<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Shipping\Application\Query\CarrierRow;
use Modules\Shipping\Application\Query\ListCarriers\CarrierCountry;
use Modules\Shipping\Application\Query\ListCarriers\ListCarriers;
use Modules\Shipping\Application\Query\ListCarriers\ListCarriersHandler;
use Modules\Shipping\Application\Query\ViewCarrier\ViewCarrier;
use Modules\Shipping\Application\Query\ViewCarrier\ViewCarrierHandler;
use Modules\Shipping\Application\ShippingPermissions;
use Shared\Application\Unauthorized;

/**
 * The carriers screens' data (shipping.md §4.1 part 1), built from step 3's queries - which authorize -
 * and the logos' thumbnails from Platform's media in one read (confirmed by the backend session,
 * 2026-10-29: `CarrierRow` stays as it is).
 */
final readonly class CarrierPages
{
    /** Either opens the carriers screens of a store (§3). */
    public const array JOBS = [ShippingPermissions::CARRIERS_VIEW, ShippingPermissions::CARRIERS_MANAGE];

    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private PlatformApi $platform,
    ) {}

    /**
     * C1.
     *
     * @throws Unauthorized
     */
    public function list(?string $storeCode, ListCarriersHandler $carriers): CarriersPage
    {
        [$store, $offered] = $this->store($storeCode);
        $locale = $this->locale();
        $countries = $carriers->handle(new ListCarriers($store->id, $locale));
        $logos = $this->logos(array_merge(...array_map(static fn (CarrierCountry $country): array => $country->carriers, $countries)));

        return new CarriersPage(
            array_map(fn (CarrierCountry $country): CarrierCountryData => new CarrierCountryData(
                $country->code,
                $country->name->in($locale),
                $country->own,
                array_map(fn (CarrierRow $row): CarrierData => self::data($row, $logos), $country->carriers),
            ), $countries),
            $this->mayManage($store),
            $store->code,
            $store->name->in($locale),
            $store->isActive,
            array_map(fn (StoreDto $each): StoreOptionData => new StoreOptionData($each->code, $each->name->in($locale), $each->isActive), $offered),
        );
    }

    /**
     * C3: the carrier, in the store the address names. A carrier of another store is not found there.
     *
     * @throws Unauthorized
     */
    public function carrier(?string $storeCode, string $carrierId, ViewCarrierHandler $view): CarrierPage
    {
        [$store] = $this->store($storeCode);
        $locale = $this->locale();
        $row = $view->handle(new ViewCarrier($store->id, strtolower($carrierId)));
        $country = null;

        foreach ($this->platform->addressCountries($locale) as $each) {
            if ($each->code === $row->countryCode) {
                $country = $each->name->in($locale);
                break;
            }
        }

        return new CarrierPage(
            self::data($row, $this->logos([$row])),
            $country ?? $row->countryCode,
            $this->mayManage($store),
            $store->code,
            $store->name->in($locale),
            $store->isActive,
        );
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
     * Add, Edit, the switch, the order and Delete are offered only to whoever may manage (Q12). Asked of
     * the stores offered, not `chosen()`, which refuses a store it does not offer rather than saying no.
     */
    private function mayManage(StoreDto $store): bool
    {
        foreach ($this->choices->forJobs(ShippingPermissions::CARRIERS_MANAGE) as $each) {
            if ($each->id === $store->id) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, string|null>  $logos */
    private static function data(CarrierRow $row, array $logos): CarrierData
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
        );
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
