<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Shipping\Presentation\Http\Controller\BoxesController;
use Modules\Shipping\Presentation\Http\Controller\CarrierPricesController;
use Modules\Shipping\Presentation\Http\Controller\CarriersController;

/*
| Shipping's screens in the admin panel (shipping.md §4.1): the Shipping menu's Carriers - price groups
| and boxes join them in parts 2 and 3.
|
| Mounted into the area App\Http\AdminArea names, as the other modules' screens are. Who may open any of
| these is Shipping's answer, never the routing's. Named shipping.admin.*, which the panel's route list
| carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('shipping/carriers', [CarriersController::class, 'index'])->name('shipping.admin.carriers');
        Route::post('shipping/carriers', [CarriersController::class, 'store'])->name('shipping.admin.carriers.store');
        // The order, named before a carrier's own routes take the word.
        Route::post('shipping/carriers/order', [CarriersController::class, 'order'])->name('shipping.admin.carriers.order');
        Route::get('shipping/carriers/{carrier}', [CarriersController::class, 'show'])->name('shipping.admin.carriers.show');
        Route::post('shipping/carriers/{carrier}', [CarriersController::class, 'update'])->name('shipping.admin.carriers.update');
        Route::post('shipping/carriers/{carrier}/switch', [CarriersController::class, 'switch'])->name('shipping.admin.carriers.switch');
        Route::post('shipping/carriers/{carrier}/delete', [CarriersController::class, 'destroy'])->name('shipping.admin.carriers.delete');
        // A carrier's prices and free-shipping amount (part 2).
        Route::get('shipping/carriers/{carrier}/places', [CarrierPricesController::class, 'places'])->name('shipping.admin.carriers.places');
        Route::post('shipping/carriers/{carrier}/prices', [CarrierPricesController::class, 'save'])->name('shipping.admin.carriers.prices.save');
        Route::post('shipping/carriers/{carrier}/prices/{group}/delete', [CarrierPricesController::class, 'delete'])->name('shipping.admin.carriers.prices.delete');
        Route::post('shipping/carriers/{carrier}/free-above', [CarrierPricesController::class, 'freeAbove'])->name('shipping.admin.carriers.free_above');

        // The boxes and the sizes packed by hand (part 3); the order and the sizes before a box's own routes.
        Route::get('shipping/boxes', [BoxesController::class, 'index'])->name('shipping.admin.boxes');
        Route::post('shipping/boxes', [BoxesController::class, 'store'])->name('shipping.admin.boxes.store');
        Route::post('shipping/boxes/order', [BoxesController::class, 'order'])->name('shipping.admin.boxes.order');
        Route::get('shipping/boxes/sizes', [BoxesController::class, 'sizes'])->name('shipping.admin.boxes.sizes');
        Route::post('shipping/boxes/sizes', [BoxesController::class, 'pack'])->name('shipping.admin.boxes.pack');
        Route::post('shipping/boxes/{box}', [BoxesController::class, 'update'])->name('shipping.admin.boxes.update');
        Route::post('shipping/boxes/{box}/switch', [BoxesController::class, 'switch'])->name('shipping.admin.boxes.switch');
        Route::post('shipping/boxes/{box}/delete', [BoxesController::class, 'destroy'])->name('shipping.admin.boxes.delete');
    });
