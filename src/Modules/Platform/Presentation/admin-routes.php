<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Presentation\Http\Controller\AuditController;
use Modules\Platform\Presentation\Http\Controller\CurrenciesController;
use Modules\Platform\Presentation\Http\Controller\MediaController;
use Modules\Platform\Presentation\Http\Controller\SettingsController;
use Modules\Platform\Presentation\Http\Controller\StoresController;

/*
| Platform's own screens in the admin panel (stage 2b step 3, frontend.md 3.5).
|
| Mounted into the area App\Http\AdminArea names: Access implements the door, and a module puts
| its pages behind it without reaching into Access to do so.
|
| Who may open any of these is Platform's answer, never the routing's. The read model behind the
| page answers for the person's own stores, and each handler asks again before it writes.
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('stores', [StoresController::class, 'index'])->name('platform.admin.stores');
        Route::post('stores/{store}', [StoresController::class, 'update'])->name('platform.admin.stores.update');

        // Currencies belong to no store, and both permissions are reserved, so only a Super Admin
        // ever sees this screen [DECIDED 2026-09-19].
        Route::get('currencies', [CurrenciesController::class, 'index'])->name('platform.admin.currencies');
        Route::post('currencies', [CurrenciesController::class, 'store'])->name('platform.admin.currencies.store');
        Route::post('currencies/{currency}', [CurrenciesController::class, 'update'])->name('platform.admin.currencies.update');

        // Every declared setting this person may change, whichever module declared it. A store
        // setting applies to the store in the header, which is where the store comes from.
        Route::get('settings', [SettingsController::class, 'index'])->name('platform.admin.settings');
        Route::post('settings/{setting}', [SettingsController::class, 'update'])->name('platform.admin.settings.update');

        // The media library. Media belongs to no store, so every question here is global.
        Route::get('media', [MediaController::class, 'index'])->name('platform.admin.media');
        Route::post('media', [MediaController::class, 'upload'])->name('platform.admin.media.upload');
        Route::post('media/{media}/alt', [MediaController::class, 'describe'])->name('platform.admin.media.alt');
        Route::post('media/{media}/retry', [MediaController::class, 'retry'])->name('platform.admin.media.retry');
        Route::post('media/{media}/delete', [MediaController::class, 'delete'])->name('platform.admin.media.delete');

        // The audit log, which is read and never written: the table refuses anything else.
        Route::get('audit', [AuditController::class, 'index'])->name('platform.admin.audit');
    });
