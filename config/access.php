<?php

declare(strict_types=1);

/*
| Access module configuration. Hosting choices live here and in .env, never in code; the security
| numbers (code lengths, link lifetimes…) are settings, changed without a deploy.
*/

return [
    'sms' => [
        // Where SMS codes go. "log" writes them to the application log, for development and tests,
        // until the owner names the SMS provider; its adapter is then added and chosen here
        // (owner's decision, 2026-09-18). Never "log" on a live system.
        'driver' => env('ACCESS_SMS_DRIVER', 'log'),
    ],

    'admin' => [
        // The admin panel's own session cookie, apart from the storefront's, so its idle limit,
        // two-factor and sign-out never touch a customer session in the same browser (spec §1.8).
        'session_cookie' => env('ACCESS_ADMIN_SESSION_COOKIE', 'touchwood_admin_session'),
        // The "trust this browser" cookie: a random token; only its hash is stored.
        'trust_cookie' => env('ACCESS_ADMIN_TRUST_COOKIE', 'touchwood_admin_trust'),
    ],
];
