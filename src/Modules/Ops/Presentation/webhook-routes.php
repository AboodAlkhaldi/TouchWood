<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Ops\Presentation\Http\Controller\BrevoWebhookController;

/*
| Brevo's marketing webhook (ops.md §1.9): machine to machine, so no session, no CSRF token and no
| name - no screen ever links to it (RouteGroupsTest passes over unnamed routes). Its secret header is
| its authority (BrevoWebhookController). `/newsletter` is Ops' own top-level path (ReservedPaths).
*/
Route::post('newsletter/brevo', BrevoWebhookController::class);
