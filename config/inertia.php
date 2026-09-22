<?php

declare(strict_types=1);

/*
| Server-side rendering (frontend.md §1.3).
|
| Every page is rendered on the server, the admin panel included. In production the renderer is a
| Node process that must run all the time, like the queue worker and the scheduler:
| `php artisan inertia:start-ssr`.
|
| **Nobody ever sees an error because of SSR** (decided 2026-09-19). When the renderer is down or a
| page fails to render there, Inertia returns the page unrendered and the browser renders it
| instead; the failure is logged (App\Listeners\LogSsrFailure) so that it is noticed and fixed
| rather than silently costing every visitor a slower first paint.
|
| Tests run with it off: a test suite cannot depend on a Node process being up, and the fallback
| itself is tested on purpose (tests/Modules/Access/Feature/AdminPagesTest.php).
*/

return [
    'ssr' => [
        'enabled' => env('INERTIA_SSR_ENABLED', true),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        // Never true in production: it turns a slower page into an error page, which is the one
        // thing SSR is not allowed to do here.
        'throw_on_error' => env('INERTIA_SSR_THROW_ON_ERROR', false),
    ],
];
