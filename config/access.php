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

    'storefront' => [
        // The visitor's guest id, in an encrypted HTTP-only cookie (spec §1.7). Access reads it to
        // say "this guest became that customer"; Sales writes it with the first cart line.
        'guest_cookie' => env('ACCESS_GUEST_COOKIE', 'touchwood_guest'),
        // How long that cookie lasts, so a cart left for a while is still theirs when they return
        // (owner's decision, 2026-09-20: a year).
        'guest_cookie_days' => (int) env('ACCESS_GUEST_COOKIE_DAYS', 365),
        // The outer bound for a storefront session's cookie and row: the longest "remember me" any
        // store may set (the setting's own maximum). Access ends a session sooner — the store's
        // idle minutes, or its remembered days.
        'session_days' => (int) env('ACCESS_STOREFRONT_SESSION_DAYS', 365),
    ],
];
