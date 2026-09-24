<?php

declare(strict_types=1);

// What each audited action is called, for the audit log (frontend.md 3.5, E6). An action with no
// line here is shown exactly as it is recorded, which is ugly and exact.
return [
    'store.created' => 'Store opened',
    'store.updated' => 'Store changed',
    'currency.created' => 'Currency added',
    'currency.updated' => 'Currency changed',
    'setting.updated' => 'Setting changed',
    'media.uploaded' => 'File uploaded',
    'media.deleted' => 'File deleted',
    'media.alt_text_changed' => 'File description changed',
    'media.variants_retried' => 'Image processing tried again',
];
