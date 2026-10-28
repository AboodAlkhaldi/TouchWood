<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RestoreAddressCountry;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\PlaceAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Domain\Repository\AddressCountryRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A country not retired is left alone.
 */
final readonly class RestoreAddressCountryHandler
{
    public const string PERMISSION = PlatformPermissions::PLACES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlaceLocks $locks,
        private AddressCountryRepository $countries,
        private ConnectionInterface $db,
        private AuditLog $auditLog,
    ) {}

    public function handle(RestoreAddressCountry $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $code = PlaceLocks::code($command->countryCode);

        $this->db->transaction(function () use ($code): void {
            $country = $this->locks->country($code);

            $country->restore();

            if ($country->pullChanges() === []) {
                return;
            }

            $this->countries->update($country);
            $this->auditLog->record(PlaceAudit::countryMarked($country));
        }, 3);
    }
}
