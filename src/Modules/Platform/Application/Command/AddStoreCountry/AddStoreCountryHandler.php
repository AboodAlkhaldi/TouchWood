<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\AddStoreCountry;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\PlaceAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Exception\AddressCountryRetired;
use Modules\Platform\Domain\Exception\CountryShippedByAnotherStore;
use Modules\Platform\Domain\Exception\CountryWithoutPlaces;
use Modules\Platform\Domain\Exception\StoreNotFound;
use Modules\Platform\Domain\Repository\AddressCountryRepository;
use Modules\Platform\Domain\Repository\StoreCountryRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Only a country on the address list, in use, with its place list (shipping.md §1.3: otherwise its
 * carriers could never be priced) and shipped to by no other store (owner, 2026-10-27 R3) - move
 * it off that store first (stage 7 step 1, Q1). Off stores too: a Super Admin readies a store
 * before turning it on. A country already on the store's list is left alone.
 */
final readonly class AddStoreCountryHandler
{
    public const string PERMISSION = PlatformPermissions::STORE_COUNTRIES;

    public function __construct(
        private Authorizer $authorizer,
        private StoreDirectory $directory,
        private PlaceLocks $locks,
        private AddressCountryRepository $countries,
        private StoreCountryRepository $storeCountries,
        private ConnectionInterface $db,
        private AuditLog $auditLog,
    ) {}

    public function handle(AddStoreCountry $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $store = $this->directory->storeByCode($command->storeCode) ?? throw new StoreNotFound($command->storeCode);
        $code = PlaceLocks::code($command->countryCode);

        $this->db->transaction(function () use ($store, $code): void {
            $country = $this->locks->country($code);

            if ($country->isRetired()) {
                throw new AddressCountryRetired($code);
            }

            $current = $this->storeCountries->storeOf($code);

            if ($current !== null) {
                if ($current->equals($store->storeId())) {
                    return;
                }

                throw new CountryShippedByAnotherStore($code, $this->directory->storeById($current->value)->code ?? $current->value);
            }

            if (! $this->countries->hasLiveCity($code)) {
                throw new CountryWithoutPlaces($code);
            }

            $this->storeCountries->add($store->storeId(), $code);
            $this->auditLog->record(PlaceAudit::shipToAdded($store->storeId(), $code));
        }, 3);
    }
}
