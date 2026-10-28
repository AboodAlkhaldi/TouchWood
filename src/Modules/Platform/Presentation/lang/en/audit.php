<?php

declare(strict_types=1);

// What each audited action is called, for the audit log (frontend.md 3.5, E6). An action with no
// line here is shown exactly as it is recorded, which is ugly and exact.
return [
    'store.created' => 'Store opened',
    'store.updated' => 'Store changed',
    'store.activated' => 'Store turned on',
    'store.deactivated' => 'Store turned off',
    'currency.created' => 'Currency added',
    'currency.updated' => 'Currency changed',
    'currency.deleted' => 'Currency deleted',
    'setting.updated' => 'Setting changed',
    'media.uploaded' => 'File uploaded',
    'media.deleted' => 'File deleted',
    'media.alt_text_changed' => 'File description changed',
    'media.variants_retried' => 'Image processing tried again',
    'failed_job.retried' => 'Failed job retried',
    'failed_job.deleted' => 'Failed job deleted',
    'address_country.added' => 'Address country added',
    'address_country.retired' => 'Address country retired',
    'address_country.restored' => 'Address country restored',
    'place.added' => 'Place added',
    'place.renamed' => 'Place renamed',
    'place.retired' => 'Place retired',
    'place.restored' => 'Place restored',
    'store_country.added' => 'Store ships to a country',
    'store_country.removed' => 'Store stops shipping to a country',
];
