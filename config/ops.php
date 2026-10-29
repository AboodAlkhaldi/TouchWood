<?php

declare(strict_types=1);

/*
| Ops module configuration (ops.md §2.2). Hosting choices live here and in .env, never in code: each
| provider is an adapter chosen here, and a new provider is a new class and a binding.
*/

return [
    'email' => [
        // Where Ops' emails go: "log" writes them to the application log, for development and tests;
        // "brevo" sends them through Brevo's transactional API with services.brevo.key. Never "log"
        // on a live system.
        'driver' => env('OPS_EMAIL_DRIVER', 'log'),
    ],

    'sms' => [
        // Where Ops' SMS go: "log" until the owner names the provider (ops.md §2.2; Mobily is being
        // checked). Never "log" on a live system.
        'driver' => env('OPS_SMS_DRIVER', 'log'),
    ],
];
