<?php

declare(strict_types=1);

use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Domain\Model\AddressPlaces;

/*
| An address's country and places as a value (access.md amendment 76): checked here on their own,
| because AddressPlaceRules refuses the same inputs a layer above (lesson 40).
*/

it('takes a country with places picked from the top down, ids lower-cased', function () {
    $places = AddressPlaces::of('sa', '01JBZ0000000000000000000AA', '01jbz0000000000000000000ab', null, 'en');

    expect([$places->countryCode, $places->regionId, $places->cityId, $places->districtId, $places->language])
        ->toBe(['SA', '01jbz0000000000000000000aa', '01jbz0000000000000000000ab', null, 'en'])
        ->and($places->ids())->toBe(['01jbz0000000000000000000aa', '01jbz0000000000000000000ab']);
});

it('refuses a city with no region, a district with no city, a malformed id or country', function (Closure $make) {
    expect($make)->toThrow(InvalidAddress::class);
})->with([
    'a city with no region' => [fn () => AddressPlaces::of('SA', null, '01jbz0000000000000000000ab', null, 'ar')],
    'a district with no city' => [fn () => AddressPlaces::of('SA', '01jbz0000000000000000000aa', null, '01jbz0000000000000000000ac', 'ar')],
    'not an id' => [fn () => AddressPlaces::of('SA', 'riyadh', null, null, 'ar')],
    'not a country' => [fn () => AddressPlaces::of('Saudi', null, null, null, 'ar')],
]);

it('reads any language other than English as Arabic, the default', function () {
    expect(AddressPlaces::of('SA', null, null, null, 'fr')->language)->toBe('ar');
});
