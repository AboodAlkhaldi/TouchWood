<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\AddPlace;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Platform\Application\Audit\PlaceAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Domain\Exception\AddressCountryRetired;
use Modules\Platform\Domain\Exception\InvalidPlace;
use Modules\Platform\Domain\Exception\PlaceNameTaken;
use Modules\Platform\Domain\Exception\PlaceNotFound;
use Modules\Platform\Domain\Exception\PlaceRetired;
use Modules\Platform\Domain\Model\Place;
use Modules\Platform\Domain\Repository\PlaceRepository;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A region straight under its country, a city in a region, a district in a city - never under
 * anything retired, and never named as another place under the same parent (stage 7 step 1, Q4,
 * Q6). The country must be on the address list and in use.
 */
final readonly class AddPlaceHandler
{
    public const string PERMISSION = PlatformPermissions::PLACES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlaceLocks $locks,
        private PlaceRepository $places,
        private ConnectionInterface $db,
        private AuditLog $auditLog,
    ) {}

    public function handle(AddPlace $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $code = PlaceLocks::code($command->countryCode);
        $kind = PlaceKind::tryFrom($command->kind) ?? throw new InvalidPlace('kind', 'expected REGION, CITY or DISTRICT');
        $name = TranslatedText::of($command->nameAr, $command->nameEn, 'name');

        return $this->db->transaction(function () use ($command, $code, $kind, $name): string {
            $country = $this->locks->country($code);

            if ($country->isRetired()) {
                throw new AddressCountryRetired($code);
            }

            $parent = null;

            if ($command->parentId !== null) {
                $parent = $this->places->byId($command->parentId) ?? throw new PlaceNotFound($command->parentId);
            }

            $place = Place::create($this->places->nextId(), $code, $kind, $parent, $name);

            if ($parent !== null && $this->locks->isHidden($parent)) {
                throw new PlaceRetired($parent->id);
            }

            $taken = $this->places->nameTaken($code, $kind, $place->parentId, $place->name());

            if ($taken !== null) {
                throw new PlaceNameTaken($taken);
            }

            try {
                $this->places->add($place);
            } catch (UniqueConstraintViolationException) {
                // Only the two name indexes are unique besides the key, which is new.
                throw new PlaceNameTaken($place->name()->en);
            }

            $this->auditLog->record(PlaceAudit::placeAdded($place));

            return $place->id;
        }, 3);
    }
}
