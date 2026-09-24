<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
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
    });
