<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RestorePlace;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\PlaceAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Domain\Repository\PlaceRepository;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Restoring brings back what sits under it, unless retired itself. A place under something still
 * retired stays out of the pickers until that comes back too. Not retired: left alone.
 */
final readonly class RestorePlaceHandler
{
    public const string PERMISSION = PlatformPermissions::PLACES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlaceLocks $locks,
        private PlaceRepository $places,
        private ConnectionInterface $db,
        private AuditLog $auditLog,
    ) {}

    public function handle(RestorePlace $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command): void {
            [, $place] = $this->locks->place($command->placeId);
            $place->restore();

            if ($place->pullChanges() === []) {
                return;
            }

            $this->places->update($place);
            $this->auditLog->record(PlaceAudit::placeMarked($place));
        }, 3);
    }
}
