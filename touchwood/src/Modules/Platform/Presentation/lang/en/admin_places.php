<?php

declare(strict_types=1);

// E8 - the address countries and their places (platform.md §4.3).
return [
    'title' => 'Countries and Places',
    'subtitle' => 'The countries a customer may choose in an address, with their regions, cities and districts.',

    'column' => [
        'country' => 'Country',
        'ships_from' => 'Ships From',
        'places' => 'Places',
        'name' => 'Name',
        'under' => 'In It',
        'actions' => 'Actions',
    ],
    'no_store' => 'No store',
    'store_off' => ':name (Off)',
    'no_places' => 'No place list yet',
    'no_places_hint' => 'Customers type their places here, and no store can ship here until it has a region.',
    'retired' => 'Retired',
    'show_retired' => 'Show retired',
    'filter' => 'Filter by name',

    'regions' => ['zero' => ':count regions', 'one' => ':count region', 'two' => ':count regions', 'few' => ':count regions', 'many' => ':count regions', 'other' => ':count regions'],
    'cities' => ['zero' => ':count cities', 'one' => ':count city', 'two' => ':count cities', 'few' => ':count cities', 'many' => ':count cities', 'other' => ':count cities'],
    'districts' => ['zero' => ':count districts', 'one' => ':count district', 'two' => ':count districts', 'few' => ':count districts', 'many' => ':count districts', 'other' => ':count districts'],

    // The countries page.
    'add_country' => 'Add Country',
    'add_country_body' => 'Customers can then choose it in their addresses. Add its regions and cities to offer them in pickers.',
    'country' => 'Country',
    'country_search' => 'Search countries',
    'country_none' => 'No countries match “:query”',
    'pick_country' => 'Choose a country first.',
    'open' => 'Open',
    'open_store' => 'Open the Store ":store"',
    'retire_country' => 'Retire Country',
    'retire_country_body' => 'Customers can no longer choose ":country" in an address, and its places leave every picker. Addresses that already name it keep it. You can restore it.',
    'restore_country' => 'Restore Country',
    'reason' => [
        'shipped' => 'Ships from the store ":store". Take it off that store\'s countries first.',
        'retired' => 'This country is retired. Restore it first.',
        'parent_retired' => 'This place is retired, with everything in it. Restore it first.',
    ],

    // A level's page.
    'kind' => [
        'REGION' => 'Region',
        'CITY' => 'City',
        'DISTRICT' => 'District',
    ],
    'add' => [
        'REGION' => 'Add Region',
        'CITY' => 'Add City',
        'DISTRICT' => 'Add District',
    ],
    'add_body' => [
        'REGION' => 'A region of :parent, named in both languages.',
        'CITY' => 'A city in :parent, named in both languages.',
        'DISTRICT' => 'A district of :parent, named in both languages.',
    ],
    'list' => [
        'REGION' => 'Regions',
        'CITY' => 'Cities',
        'DISTRICT' => 'Districts',
    ],
    'rename' => 'Rename',
    'rename_title' => 'Rename :name',
    'rename_body' => 'Addresses and prices that name it show the new name.',
    'save' => 'Save Names',
    'name_ar' => 'Name in Arabic',
    'name_en' => 'Name in English',
    'retire' => 'Retire',
    'retire_title' => 'Retire :name',
    'retire_body' => [
        'REGION' => '":name" leaves the address form and the shipping price pickers, with its :cities and :districts. Addresses and prices that already name them keep them. You can restore it.',
        'CITY' => '":name" leaves the address form and the shipping price pickers, with its :districts. Addresses and prices that already name them keep them. You can restore it.',
        'DISTRICT' => '":name" leaves the address form and the shipping price pickers. Addresses and prices that already name it keep it. You can restore it.',
    ],
    'restore' => 'Restore',
    'under_retired' => 'This :kind is retired, with everything in it: none of it is in the address form or the price pickers until it is restored.',
    'kind_lower' => [
        'COUNTRY' => 'country',
        'REGION' => 'region',
        'CITY' => 'city',
    ],
    'empty' => [
        'REGION' => ['title' => 'No Regions Yet', 'body' => 'Add the country\'s first region, then its cities.'],
        'CITY' => ['title' => 'No Cities in This Region Yet', 'body' => 'Add its first city.'],
        'DISTRICT' => ['title' => 'No Districts in This City', 'body' => 'A city needs no districts: the address form then asks for none.'],
    ],
    'none_found' => 'Nothing here is called ":text".',
    'all_retired' => 'Everything here is retired. Turn on Show retired to see it.',

    'toast' => [
        'country_added' => 'Country added',
        'country_retired' => 'Country retired',
        'country_restored' => 'Country restored',
        'place_added' => 'Place added',
        'place_renamed' => 'Names saved',
        'place_retired' => 'Place retired',
        'place_restored' => 'Place restored',
    ],
];
