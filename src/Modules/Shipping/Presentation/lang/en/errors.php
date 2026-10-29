<?php

declare(strict_types=1);

// Shipping's error messages, by error type (shipping.{key}), and the names of the fields they point at
// (shipping.md §7).
return [
    'carrier_not_found' => [
        'title' => 'Carrier Not Found',
        'detail' => 'No such carrier was found in this store.',
    ],
    'carrier_still_on' => [
        'title' => 'Carrier Still On',
        'detail' => 'Switch ":name_en" off before deleting it.',
    ],
    'country_not_shipped' => [
        'title' => 'Country Not Shipped To',
        'detail' => 'This store doesn\'t ship to ":country". A Super Admin adds it to the store\'s countries first.',
    ],
    'country_has_carriers' => [
        'title' => 'Country Has Carriers',
        'detail' => 'The store still has :carriers carrier(s) in ":country". Switch them off and delete them first.',
    ],
    'carrier_name_taken' => [
        'title' => 'Name Already Used',
        'detail' => 'Another carrier of this store is named ":name_en". Choose another name.',
    ],
    'price_group_not_found' => [
        'title' => 'Price Not Found',
        'detail' => 'This price was not found for this carrier. Reload the page.',
    ],
    'place_already_priced' => [
        'title' => 'Place Already Priced',
        'detail' => '":name_en" is already at :amount in this carrier. Choose Move Here to move it to this price.',
    ],
    'amount_already_used' => [
        'title' => 'Amount Already Used',
        'detail' => 'This carrier already has a :amount price. Add the places there.',
    ],
    'place_not_in_country' => [
        'title' => 'Place Not Available',
        'detail' => 'One of the places isn\'t in use in this carrier\'s country. Reload the page and choose again.',
    ],
    'invalid_shipping_input' => [
        'title' => 'Check the Details',
        'detail' => 'The :field isn\'t valid. Check it and try again.',
    ],
    'fields' => [
        'store' => 'store',
        'name_ar' => 'Arabic name',
        'name_en' => 'English name',
        'logo_media_id' => 'logo',
        'phone' => 'phone',
        'website' => 'website',
        'tracking_pattern' => 'tracking link',
        'carriers' => 'carriers\' order',
        'amount' => 'amount',
        'places' => 'places',
        'free_above' => 'free shipping amount',
    ],
];
