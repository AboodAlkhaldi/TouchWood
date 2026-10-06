<?php

declare(strict_types=1);

/*
| Catalog module configuration. Hosting choices live here and in .env, never in code.
*/

return [
    'imports' => [
        // Where a product file's zip waits until its products are brought in (catalog.md §1.12,
        // amendment 6). The queue worker that brings them in reads it from here, so with more than
        // one server, point it at shared storage (S3-compatible), as the media disks are.
        'disk' => env('CATALOG_IMPORTS_DISK', 'local'),
    ],
];
