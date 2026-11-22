<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RetirePlace;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\PlaceAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Domain\Repository\PlaceRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Retiring hides everything under the place too, without touching their own marks (stage 7 step 1,
 * Q4). Its prices and the addresses that name it keep it (shipping.md §9.3 #4). Already retired: left alone.
 */
final readonly class RetirePlaceHandler
{
    public const string PERMISSION = PlatformPermissions::PLACES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlaceLocks $locks,
        private PlaceRepository $places,
        private ConnectionInterface $db,
        private AuditLog $auditLog,
    ) {}

    public function handle(RetirePlace $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command): void {
            [, $place] = $this->locks->place($command->placeId);
            $place->retire();

            if ($place->pullChanges() === []) {
                return;
            }

            $this->places->update($place);
            $this->auditLog->record(PlaceAudit::placeMarked($place));
        }, 3);
    }
}
