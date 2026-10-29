<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Ops\Presentation\Http\Controller\BellController;

/*
| The panel's bell (ops.md §1.2, §4): a signed-in staff member's own entries, over the stores they may
| view.
|
| Mounted into the area App\Http\AdminArea names, as the other modules' screens are. Named ops.admin.*,
| which the panel's route list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('notifications', [BellController::class, 'index'])->name('ops.admin.bell');
        Route::get('notifications/unread', [BellController::class, 'unread'])->name('ops.admin.bell.unread');
        // Every entry, named before one entry's own route takes the word.
        Route::post('notifications/read-all', [BellController::class, 'readAll'])->name('ops.admin.bell.read-all');
        Route::post('notifications/{entry}/read', [BellController::class, 'read'])->name('ops.admin.bell.read');
    });
