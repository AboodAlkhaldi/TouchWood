<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Audit;

use Modules\Platform\Domain\Model\AddressCountry;
use Modules\Platform\Domain\Model\Place;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * Audit entries for the address countries, the places and the stores' ship-to countries
 * (platform.md, stage 7). None of it is personal data, so every value is kept.
 */
final class PlaceAudit
{
    public static function countryAdded(AddressCountry $country): AuditEntryDto
    {
        return new AuditEntryDto('platform.address_country.added', 'platform.address_country', $country->code, null, AuditChanges::none()->changed('code', null, $country->code));
    }

    /**
     * Retired or restored, by the mark it now has.
     */
    public static function countryMarked(AddressCountry $country): AuditEntryDto
    {
        $retired = $country->isRetired();

        return new AuditEntryDto(
            $retired ? 'platform.address_country.retired' : 'platform.address_country.restored',
            'platform.address_country',
            $country->code,
            null,
            AuditChanges::none()->changed('retired', ! $retired, $retired),
        );
    }

    public static function placeAdded(Place $place): AuditEntryDto
    {
        return new AuditEntryDto('platform.place.added', 'platform.place', $place->id, null, AuditChanges::none()
            ->changed('country', null, $place->countryCode)
            ->changed('kind', null, $place->kind->value)
            ->changed('parent_id', null, $place->parentId)
            ->changed('name', null, ['ar' => $place->name()->ar, 'en' => $place->name()->en]));
    }

    /**
     * @param  array{ar: string, en: string}  $before
     */
    public static function placeRenamed(Place $place, array $before): AuditEntryDto
    {
        return new AuditEntryDto('platform.place.renamed', 'platform.place', $place->id, null, AuditChanges::none()
            ->changed('name', $before, ['ar' => $place->name()->ar, 'en' => $place->name()->en]));
    }

    /**
     * Retired or restored, by the mark it now has.
     */
    public static function placeMarked(Place $place): AuditEntryDto
    {
        $retired = $place->isRetired();

        return new AuditEntryDto(
            $retired ? 'platform.place.retired' : 'platform.place.restored',
            'platform.place',
            $place->id,
            null,
            AuditChanges::none()->changed('retired', ! $retired, $retired),
        );
    }

    public static function shipToAdded(StoreId $store, string $countryCode): AuditEntryDto
    {
        return new AuditEntryDto('platform.store_country.added', 'platform.store', $store->value, $store->value, AuditChanges::none()->changed('country', null, $countryCode));
    }

    public static function shipToRemoved(StoreId $store, string $countryCode): AuditEntryDto
    {
        return new AuditEntryDto('platform.store_country.removed', 'platform.store', $store->value, $store->value, AuditChanges::none()->changed('country', $countryCode, null));
    }
}
