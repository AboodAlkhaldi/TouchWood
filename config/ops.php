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

    // SMS have no driver here: each goes through the provider of the phone's country on the SMS
    // countries list (access.md amendment 87). A provider is an adapter, bound in OpsServiceProvider;
    // its keys go in .env and are read here.

    'newsletter' => [
        // Where the newsletter's lists are kept (ops.md §1.9): "log" writes the changes to the
        // application log, for development and tests; "brevo" keeps one Brevo list per store and
        // language, with services.brevo.key and services.brevo.newsletter_folder. Never "log" on a
        // live system.
        'driver' => env('OPS_NEWSLETTER_DRIVER', 'log'),
    ],
];
