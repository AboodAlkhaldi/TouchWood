<?php

declare(strict_types=1);

use Modules\Access\Application\Address\StartingAddressFormat;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Domain\Model\StoreAddressFormat;
use Modules\Access\Domain\ValueObject\AddressField;
use Modules\Access\Domain\ValueObject\MapPin;

/**
 * A store's address form and its rules (spec §1.9, amendment 41). Nothing here touches the
 * database: the format refuses a bad address before any row is written.
 */
function addressFormat(string $template = "{street}, {district}\n{city} {postal_code}"): StoreAddressFormat
{
    return StoreAddressFormat::of('store-sa', [
        AddressField::of('city', 'المدينة', 'City', true, 100, 1),
        AddressField::of('street', 'الشارع', 'Street', true, 200, 0),
        AddressField::of('district', 'الحي', 'District', false, 100, 2),
        AddressField::of('postal_code', 'الرمز البريدي', 'Postal code', false, 10, 3),
    ], $template);
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function addressValues(array $overrides = []): array
{
    return [...['city' => 'Riyadh', 'street' => 'King Fahd Road'], ...$overrides];
}

describe('a store\'s address format (spec §1.9)', function () {
    it('keeps the values it defines, trimmed and in its own order', function () {
        $kept = addressFormat()->accept(['district' => ' Al Olaya ', 'city' => 'Riyadh', 'street' => 'King Fahd Road']);

        expect($kept)->toBe(['street' => 'King Fahd Road', 'city' => 'Riyadh', 'district' => 'Al Olaya'])
            ->and(array_keys($kept))->toBe(['street', 'city', 'district']);
    });

    it('refuses a field it does not define', function () {
        expect(fn () => addressFormat()->accept(addressValues(['province' => 'Riyadh'])))
            ->toThrow(InvalidAddress::class, 'province');
    });

    it('refuses a required field that is missing or only spaces', function (array $values) {
        expect(fn () => addressFormat()->accept($values))->toThrow(InvalidAddress::class, 'city');
    })->with([
        'missing' => [['street' => 'King Fahd Road']],
        'blank' => [['street' => 'King Fahd Road', 'city' => '   ']],
    ]);

    it('refuses a value longer than the field allows', function () {
        expect(fn () => addressFormat()->accept(addressValues(['postal_code' => str_repeat('1', 11)])))
            ->toThrow(InvalidAddress::class, 'postal_code');
    });

    it('drops an optional field left empty', function () {
        expect(addressFormat()->accept(addressValues(['district' => ' '])))->toBe(['street' => 'King Fahd Road', 'city' => 'Riyadh']);
    });

    it('says whether values still satisfy it', function () {
        $stricter = StoreAddressFormat::of('store-sa', [
            AddressField::of('city', 'المدينة', 'City', true, 100, 0),
            AddressField::of('street', 'الشارع', 'Street', true, 200, 1),
            AddressField::of('floor', 'الدور', 'Floor', true, 50, 2),
        ], '{city}');

        expect(addressFormat()->satisfiedBy(addressValues()))->toBeTrue()
            ->and($stricter->satisfiedBy(addressValues()))->toBeFalse()
            ->and($stricter->satisfiedBy(addressValues(['floor' => '3'])))->toBeTrue();
    });

    it('prints it by the template, dropping what is empty', function () {
        expect(addressFormat()->render(['street' => 'King Fahd Road', 'city' => 'Riyadh', 'district' => 'Al Olaya', 'postal_code' => '12345']))
            ->toBe("King Fahd Road, Al Olaya\nRiyadh 12345")
            // No district: its comma goes with it. No postal code: the line is just the city.
            ->and(addressFormat()->render(['street' => 'King Fahd Road', 'city' => 'Riyadh']))
            ->toBe("King Fahd Road\nRiyadh");
    });

    it('drops a line that has nothing left on it', function () {
        expect(addressFormat("{street}\n{district}\n{city}")->render(['street' => 'King Fahd Road', 'city' => 'Riyadh']))
            ->toBe("King Fahd Road\nRiyadh");
    });

    it('puts nothing in a template but values', function () {
        expect(addressFormat('{street}')->render(['street' => '{city} <b>x</b>']))->toBe('{city} <b>x</b>');
    });

    it('refuses a template naming a field it does not have', function () {
        expect(fn () => addressFormat('{city} {country}'))->toThrow(InvalidAddress::class, 'display_template');
    });

    it('refuses two fields with one key, and a format with none', function () {
        expect(fn () => StoreAddressFormat::of('store-sa', [
            AddressField::of('city', 'المدينة', 'City', true, 100, 0),
            AddressField::of('city', 'المدينة', 'Town', false, 100, 1),
        ], '{city}'))->toThrow(InvalidAddress::class, 'fields')
            ->and(fn () => StoreAddressFormat::of('store-sa', [], ''))->toThrow(InvalidAddress::class, 'fields');
    });

    it('refuses a field definition nobody could use', function (Closure $make) {
        expect($make)->toThrow(InvalidAccessAttribute::class);
    })->with([
        'a key with spaces' => [fn () => AddressField::of('post code', 'أ', 'A', false, 10, 0)],
        'no Arabic name' => [fn () => AddressField::of('city', '  ', 'City', false, 10, 0)],
        'no length' => [fn () => AddressField::of('city', 'المدينة', 'City', false, 0, 0)],
        'an order out of range' => [fn () => AddressField::of('city', 'المدينة', 'City', false, 10, 1000)],
    ]);

    it('starts every store with the scheme the owner set, the country being the store itself', function () {
        $format = StartingAddressFormat::forStore('store-sa');
        $required = array_values(array_map(
            static fn (AddressField $field): string => $field->key,
            array_filter($format->fields, static fn (AddressField $field): bool => $field->required),
        ));

        expect($required)->toBe(['administrative_area', 'city', 'district', 'street', 'building'])
            ->and($format->field('additional_information')?->maxLength)->toBe(500)
            ->and($format->field('street')?->maxLength)->toBe(200)
            ->and($format->field('country'))->toBeNull()
            ->and($format->render(['city' => 'Riyadh', 'street' => 'King Fahd Road', 'building' => '7', 'district' => 'Al Olaya', 'administrative_area' => 'Riyadh']))
            ->toBe("7 King Fahd Road\nAl Olaya\nRiyadh");
    });
});

describe('a map pin (spec §1.9)', function () {
    it('is both coordinates or neither', function () {
        expect(MapPin::optional(null, null))->toBeNull()
            ->and(fn () => MapPin::optional(24.7136, null))->toThrow(InvalidAccessAttribute::class, 'map_pin')
            ->and(fn () => MapPin::optional(null, 46.6753))->toThrow(InvalidAccessAttribute::class, 'map_pin');
    });

    it('refuses coordinates off the globe', function () {
        expect(fn () => MapPin::of(91.0, 0.0))->toThrow(InvalidAccessAttribute::class, 'latitude')
            ->and(fn () => MapPin::of(0.0, 181.0))->toThrow(InvalidAccessAttribute::class, 'longitude');
    });

    it('keeps six decimals, as the column does', function () {
        $pin = MapPin::of(24.71361234, 46.67529876);

        expect($pin->latitude)->toBe(24.713612)
            ->and($pin->longitude)->toBe(46.675299);
    });
});
