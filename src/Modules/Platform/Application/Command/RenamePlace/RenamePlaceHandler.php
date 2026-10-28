<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\RenamePlace;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Platform\Application\Audit\PlaceAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Domain\Exception\PlaceNameTaken;
use Modules\Platform\Domain\Repository\PlaceRepository;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A retired place may be renamed too. The addresses that name it keep the text they were saved
 * with (access.md amendment 76(e)); the pickers and the price lists show the new name.
 */
final readonly class RenamePlaceHandler
{
    public const string PERMISSION = PlatformPermissions::PLACES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlaceLocks $locks,
        private PlaceRepository $places,
        private ConnectionInterface $db,
        private AuditLog $auditLog,
    ) {}

    public function handle(RenamePlace $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $name = TranslatedText::of($command->nameAr, $command->nameEn, 'name');

        $this->db->transaction(function () use ($command, $name): void {
            [, $place] = $this->locks->place($command->placeId);
            $before = ['ar' => $place->name()->ar, 'en' => $place->name()->en];
            $place->rename($name);

            if ($place->pullChanges() === []) {
                return;
            }

            $taken = $this->places->nameTaken($place->countryCode, $place->kind, $place->parentId, $place->name(), $place->id);

            if ($taken !== null) {
                throw new PlaceNameTaken($taken);
            }

            try {
                $this->places->update($place);
            } catch (UniqueConstraintViolationException) {
                throw new PlaceNameTaken($place->name()->en);
            }

            $this->auditLog->record(PlaceAudit::placeRenamed($place, $before));
        }, 3);
    }
}
