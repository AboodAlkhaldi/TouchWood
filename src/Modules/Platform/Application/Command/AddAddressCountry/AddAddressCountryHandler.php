<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\AddAddressCountry;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\PlaceAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Domain\Exception\AddressCountryRetired;
use Modules\Platform\Domain\Model\AddressCountry;
use Modules\Platform\Domain\Repository\AddressCountryRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A country already on the list is left alone; a retired one is brought back by Restore, not here,
 * so adding never quietly undoes a retire.
 */
final readonly class AddAddressCountryHandler
{
    public const string PERMISSION = PlatformPermissions::PLACES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private AddressCountryRepository $countries,
        private ConnectionInterface $db,
        private AuditLog $auditLog,
    ) {}

    public function handle(AddAddressCountry $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $country = AddressCountry::add(PlaceLocks::code($command->countryCode));

        $this->db->transaction(function () use ($country): void {
            // Added here, or already there - then read under its lock, so two adds at once never
            // collide on the key.
            if ($this->countries->add($country)) {
                $this->auditLog->record(PlaceAudit::countryAdded($country));

                return;
            }

            if ($this->countries->byCode($country->code)?->isRetired() === true) {
                throw new AddressCountryRetired($country->code);
            }
        }, 3);
    }
}
