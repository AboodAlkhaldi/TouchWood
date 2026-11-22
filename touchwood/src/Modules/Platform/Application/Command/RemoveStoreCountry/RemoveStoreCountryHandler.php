<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RemoveStoreCountry;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\PlaceAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Places\InMemoryShipToCountryGuards;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Exception\StoreNotFound;
use Modules\Platform\Domain\Exception\StoreOwnCountry;
use Modules\Platform\Domain\Repository\AddressCountryRepository;
use Modules\Platform\Domain\Repository\StoreCountryRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Never the store's own country (shipping.md §1.3). Each registered guard is asked first, inside
 * the transaction with the store's row for the country locked: Shipping refuses while the store
 * has a carrier there (§9.3 #9). Saved addresses stay; the rules make them `OUTSIDE` or
 * `OTHER_STORE`. A country not on the store's list is left alone. Moving a country is this, then
 * `AddStoreCountry` for the other store.
 */
final readonly class RemoveStoreCountryHandler
{
    public const string PERMISSION = PlatformPermissions::STORE_COUNTRIES;

    public function __construct(
        private Authorizer $authorizer,
        private StoreDirectory $directory,
        private AddressCountryRepository $countries,
        private StoreCountryRepository $storeCountries,
        private InMemoryShipToCountryGuards $guards,
        private ConnectionInterface $db,
        private AuditLog $auditLog,
    ) {}

    public function handle(RemoveStoreCountry $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $store = $this->directory->storeByCode($command->storeCode) ?? throw new StoreNotFound($command->storeCode);
        $code = PlaceLocks::code($command->countryCode);

        if ($code === $store->countryCode) {
            throw new StoreOwnCountry($code, $store->code);
        }

        $this->db->transaction(function () use ($store, $code): void {
            // The country's row first, as every change of the places takes it.
            if ($this->countries->byCode($code) === null) {
                return;
            }

            $current = $this->storeCountries->storeOf($code);

            if ($current === null || ! $current->equals($store->storeId())) {
                return;
            }

            $this->guards->check($store->storeId(), $code);
            $this->storeCountries->remove($store->storeId(), $code);
            $this->auditLog->record(PlaceAudit::shipToRemoved($store->storeId(), $code));
        }, 3);
    }
}
