<?php

declare(strict_types=1);

namespace Modules\Access\Application\Address;

use Modules\Access\Domain\Model\StoreAddressFormat;
use Modules\Access\Domain\ValueObject\AddressField;

/**
 * The scheme every store starts with (spec §1.9, amendment 41): the sixteen fields the owner
 * decided, named in both languages, each with the length the owner set, and the layout used on
 * orders and shipping labels. It is written when the schema is migrated and when a store is opened
 * later; from then on it is data, and staff may change a store's copy without a deploy.
 *
 * `country` is not a field here: an address belongs to a store, and the store is its country.
 */
final class StartingAddressFormat
{
    /** @var list<array{string, string, string, bool, int}> key, Arabic, English, required, length */
    private const array FIELDS = [
        ['administrative_area', 'المنطقة', 'Region', true, 100],
        ['city', 'المدينة', 'City', true, 100],
        ['district', 'الحي', 'District', true, 100],
        ['street', 'الشارع', 'Street', true, 200],
        ['building', 'رقم المبنى', 'Building', true, 50],
        ['unit', 'رقم الوحدة', 'Unit', false, 50],
        ['floor', 'الدور', 'Floor', false, 50],
        ['postal_code', 'الرمز البريدي', 'Postal code', false, 50],
        ['additional_number', 'الرقم الإضافي', 'Additional number', false, 50],
        ['po_box', 'صندوق البريد', 'PO Box', false, 50],
        ['short_address', 'العنوان المختصر', 'Short address', false, 50],
        ['landmark', 'علامة مميزة', 'Landmark', false, 200],
        ['additional_information', 'معلومات إضافية', 'Additional information', false, 500],
    ];

    private const string TEMPLATE = "{building} {street}\n{unit} {floor}\n{district}\n{city} {postal_code}\n{short_address}\n{additional_number} {po_box}\n{landmark}\n{additional_information}";

    public static function forStore(string $storeId): StoreAddressFormat
    {
        $fields = [];

        foreach (self::FIELDS as $order => [$key, $ar, $en, $required, $max]) {
            $fields[] = AddressField::of($key, $ar, $en, $required, $max, $order);
        }

        return StoreAddressFormat::of($storeId, $fields, self::TEMPLATE);
    }
}
