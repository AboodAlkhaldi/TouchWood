<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RetireAddressCountry;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\PlaceAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Exception\AddressCountryInUse;
use Modules\Platform\Domain\Repository\AddressCountryRepository;
use Modules\Platform\Domain\Repository\StoreCountryRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Refused while a store ships to the country (stage 7 step 1, Q2): it is taken off every store's
 * list first. Its places keep their own marks and come back with it.
 */
final readonly class RetireAddressCountryHandler
{
    public const string PERMISSION = PlatformPermissions::PLACES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlaceLocks $locks,
        private AddressCountryRepository $countries,
        private StoreCountryRepository $storeCountries,
        private StoreDirectory $directory,
        private ConnectionInterface $db,
        private AuditLog $auditLog,
    ) {}

    public function handle(RetireAddressCountry $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $code = PlaceLocks::code($command->countryCode);

        $this->db->transaction(function () use ($code): void {
            $country = $this->locks->country($code);

            $store = $this->storeCountries->storeOf($country->code);

            if ($store !== null) {
                throw new AddressCountryInUse($country->code, $this->directory->storeById($store->value)->code ?? $store->value);
            }

            $country->retire();

            if ($country->pullChanges() === []) {
                return;
            }

            $this->countries->update($country);
            $this->auditLog->record(PlaceAudit::countryMarked($country));
        }, 3);
    }
}
