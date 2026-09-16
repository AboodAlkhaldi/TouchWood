<?php

declare(strict_types=1);

/*
| Platform module configuration. Hosting choices live here and in .env, never in code.
*/

return [
    'media' => [
        // Two different disks from config/filesystems.php, never the same one:
        // - public: only the resized variants of public images. Point it at S3-compatible storage
        //   with the CDN as its URL once a provider is chosen.
        // - private: every original, public or private, and every private document. It must
        //   support temporary (signed) URLs and must never be served by the CDN.
        // File visibility comes from each disk's own configuration, never from code.
        'public_disk' => env('MEDIA_PUBLIC_DISK', 'public'),
        'private_disk' => env('MEDIA_PRIVATE_DISK', 'local'),

        // How long a link to a private file works (owner's decision, 2026-09-16).
        'private_link_minutes' => 30,
    ],
];
