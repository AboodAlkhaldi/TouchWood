<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Ops\Presentation\Http\Controller\BrevoWebhookController;
use Modules\Ops\Presentation\Http\Controller\WhatsAppWebhookController;

/*
| Brevo's marketing webhook (ops.md §1.9): machine to machine, so no session, no CSRF token and no
| name - no screen ever links to it (RouteGroupsTest passes over unnamed routes). Its secret header is
| its authority (BrevoWebhookController). `/newsletter` is Ops' own top-level path (ReservedPaths).
*/
Route::post('newsletter/brevo', BrevoWebhookController::class);

/*
| Meta's WhatsApp webhook (ops.md §9.8 point 4), built the same way: GET is Meta's handshake (the verify token), POST
| its notices (the app secret's signature, X-Hub-Signature-256). `/whatsapp` is Ops' own top-level path (ReservedPaths).
*/
Route::match(['GET', 'POST'], 'whatsapp/webhook', WhatsAppWebhookController::class);
