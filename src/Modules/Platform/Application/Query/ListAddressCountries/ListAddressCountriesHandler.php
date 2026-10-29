<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListAddressCountries;

use Modules\Platform\Application\Query\NameOrder;
use Modules\Platform\Application\Query\PlacesPanelReader;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\ValueObject\IsoCountries;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The address countries page (platform.md §4.3 B1): every country, retired ones included, each with
 * the store that ships there and its places in use. Super Admins only, as the changes are; it is
 * also what the stores page's Add picker offers.
 */
final readonly class ListAddressCountriesHandler
{
    public const string PERMISSION = PlatformPermissions::PLACES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlacesPanelReader $panel,
        private StoreDirectory $directory,
    ) {}

    /**
     * @return list<AddressCountryRow>
     */
    public function handle(ListAddressCountries $query): array
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        return NameOrder::sort(
            $this->rows(),
            $query->locale,
            static fn (AddressCountryRow $row): string => $row->name->in($query->locale),
        );
    }

    /**
     * Also read by ListPlaces, for the country a page of places sits in.
     *
     * @return list<AddressCountryRow>
     */
    public function rows(): array
    {
        $storeOf = [];
        $stores = [];

        foreach ($this->directory->stores() as $store) {
            $stores[$store->id] = $store;
        }

        foreach ($this->panel->shipsTo() as $storeId => $codes) {
            foreach ($codes as $code) {
                $storeOf[$code] = $stores[$storeId] ?? null;
            }
        }

        $rows = [];

        foreach ($this->panel->countries() as $country) {
            $code = $country['code'];
            $store = $storeOf[$code] ?? null;

            $rows[] = new AddressCountryRow(
                $code,
                new TranslatedTextDto(IsoCountries::name($code, 'ar'), IsoCountries::name($code, 'en')),
                $country['retired'],
                $country['regions'] > 0,
                $store instanceof StoreDto ? new ShippingStore($store->id, $store->code, $store->name, $store->isActive) : null,
                $country['regions'],
                $country['cities'],
            );
        }

        return $rows;
    }
}
