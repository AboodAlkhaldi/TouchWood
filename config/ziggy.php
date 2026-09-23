<?php

declare(strict_types=1);

/*
| Named routes, split by area (frontend.md §1.4).
|
| An admin page receives only the admin group; a storefront page only the storefront group. A
| shopper's page therefore never contains the admin URLs.
|
| This is tidiness, not protection. Ziggy's own README says as much, and nothing here relies on it:
| every admin route still checks the person's permission in its handler (handoff §19). A test
| asserts that every named route belongs to exactly one group, so a new route cannot quietly reach
| neither list - or both.
*/

return [
    'groups' => [
        'admin' => ['access.staff.*', 'admin.*', 'platform.admin.*'],
        // platform.choose-store is brand.com with no store in the address - the storefront's front
        // door, so it belongs to the shop's list, not the panel's.
        'storefront' => ['storefront.*', 'platform.choose-store'],
    ],
];
