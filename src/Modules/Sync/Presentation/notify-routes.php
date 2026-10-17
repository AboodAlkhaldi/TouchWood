<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Sync\Presentation\Http\Controller\NotificationController;

/*
| The provider's notice (sync.md §1.3): machine to machine, so no session, no CSRF token and no name -
| no screen ever links to it (RouteGroupsTest passes over unnamed routes). The signature is its
| authority (NotificationReceiver). Its parameter is not `{store}`, which Platform binds to a store's code.
*/

// A store id is a ULID: anything else is no route, before any work or any rate-limit row.
Route::post('sync/notify/{storeId}', NotificationController::class)->whereUlid('storeId');
