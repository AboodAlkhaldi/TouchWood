<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Sync\Presentation\Http\Controller\ProviderController;
use Modules\Sync\Presentation\Http\Controller\SyncReadController;
use Modules\Sync\Presentation\Http\Controller\SyncReportController;

/*
| Sync's screens in the admin panel (sync.md §4.1.2, stage 5's screens step 6): the Sync Report and its
| changes, a read's page, and the Provider page (a Super Admin's) and its changes.
|
| Mounted into the area App\Http\AdminArea names, as Pricing's and Inventory's screens are. Who may open or
| do any of these is Sync's answer, never the routing's: each read and each handler asks for its permission
| in the store (§3).
|
| Named sync.admin.*, which the panel's route list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('sync', [SyncReportController::class, 'index'])->name('sync.admin.report');
        Route::post('sync/now', [SyncReportController::class, 'now'])->name('sync.admin.now');
        Route::post('sync/entries/{entry}/retry', [SyncReportController::class, 'retry'])->where('entry', '[0-9]{1,18}')->name('sync.admin.entries.retry');
        Route::post('sync/entries/{entry}/dismiss', [SyncReportController::class, 'dismiss'])->where('entry', '[0-9]{1,18}')->name('sync.admin.entries.dismiss');

        // A read's id is a ULID; anything else is no read - the not-found page, as one pruned.
        Route::get('sync/reads/{read}', [SyncReadController::class, 'show'])->where('read', '[0-9A-Za-z]{1,64}')->name('sync.admin.reads.show');

        Route::get('sync/provider', [ProviderController::class, 'index'])->name('sync.admin.provider');
        Route::post('sync/provider/wire', [ProviderController::class, 'wire'])->name('sync.admin.provider.wire');
        Route::post('sync/provider/change', [ProviderController::class, 'change'])->name('sync.admin.provider.change');
        Route::post('sync/provider/unwire', [ProviderController::class, 'unwire'])->name('sync.admin.provider.unwire');
    });
