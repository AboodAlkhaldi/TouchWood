<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Checkout;

use Modules\Access\Public\Dto\AddressDto;

/** The try's copy of the delivery address (§9.5 #4): the order is made from it even if the address is deleted meanwhile. */
final class TryAddress
{
    /** @return array<string, mixed> */
    public static function toArray(AddressDto $address): array
    {
        return get_object_vars($address);
    }

    /** @param  array<string, mixed>  $copy  as `toArray` wrote it */
    public static function fromArray(array $copy): AddressDto
    {
        /** @var array{id: string, customerId: string, storeId: string, label: string, recipientName: string, fields: array<string, string>, latitude: float|int|null, longitude: float|int|null, isDefault: bool, isComplete: bool, formatted: string, countryCode: string, regionId: ?string, cityId: ?string, districtId: ?string} $copy */
        return new AddressDto(
            $copy['id'], $copy['customerId'], $copy['storeId'], $copy['label'], $copy['recipientName'], $copy['fields'],
            $copy['latitude'] === null ? null : (float) $copy['latitude'], $copy['longitude'] === null ? null : (float) $copy['longitude'],
            $copy['isDefault'], $copy['isComplete'], $copy['formatted'], $copy['countryCode'], $copy['regionId'], $copy['cityId'], $copy['districtId'],
        );
    }
}
