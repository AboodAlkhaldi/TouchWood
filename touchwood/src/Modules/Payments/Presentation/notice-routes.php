<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payments\Presentation\Http\Controller\PaymentNoticeController;

/*
| The gateway's notice (payments.md §1.2): machine to machine, so no session, no CSRF token and no name - no
| screen ever links to it. The signature is its authority (NoticeReceiver). Its parameter is not `{store}`,
| which Platform binds to a store's code. An off store's still answers: its open attempts finish (§1.10).
*/

// A store id is a ULID, a mode one of two: anything else is no route, before any work or any rate-limit row.
Route::post('payments/notice/{storeId}/{mode}', PaymentNoticeController::class)->whereUlid('storeId')->whereIn('mode', ['test', 'live']);
