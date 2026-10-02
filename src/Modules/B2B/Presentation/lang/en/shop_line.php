<?php

declare(strict_types=1);

// The line under the shop's header for a company account that cannot order (b2b.md §4.4), each a
// link to the company page (CompanyShopperLine). Nothing once approved.
return [
    'continue' => 'Continue your company application',
    'finish' => 'Finish and send your company application',
    'pending' => 'Your account is under review — you can browse and fill your cart; ordering opens once it is approved.',
    'rejected' => 'Your company application was not approved — see why, and apply again.',
    'suspended' => 'Your company account is suspended: :reason',
    // A company in another store, none here: each store approves its own (amendment 19(c)).
    'apply_here' => 'Apply in this store to order here: each store approves its own companies.',
];
