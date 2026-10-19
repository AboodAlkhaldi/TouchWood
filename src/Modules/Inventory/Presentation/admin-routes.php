<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Inventory\Presentation\Http\Controller\LowStockController;
use Modules\Inventory\Presentation\Http\Controller\StockController;

/*
| Inventory's screens in the admin panel (inventory.md §4.3.2, stage 5's screens step 5): Stock and Low Stock,
| the reads their dialogs and drawer ask for (JSON: a size's figures, its history's next page, Add Size's
| picker) and every change.
|
| Mounted into the area App\Http\AdminArea names, as Catalog's and Pricing's screens are. Who may open or do
| any of these is Inventory's answer, never the routing's: each read and each handler asks for Manage Stock
| in the store (§3).
|
| Named inventory.admin.*, which the panel's route list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('stock', [StockController::class, 'index'])->name('inventory.admin.stock');
        Route::get('stock/size', [StockController::class, 'size'])->name('inventory.admin.stock.size');
        Route::get('stock/history', [StockController::class, 'history'])->name('inventory.admin.stock.history');
        Route::get('stock/add-sizes', [StockController::class, 'addSizes'])->name('inventory.admin.stock.add-sizes');
        Route::post('stock/counts', [StockController::class, 'counts'])->name('inventory.admin.stock.counts');
        Route::post('stock/adjust', [StockController::class, 'adjust'])->name('inventory.admin.stock.adjust');
        Route::post('stock/thresholds', [StockController::class, 'thresholds'])->name('inventory.admin.stock.thresholds');
        Route::post('stock/last-pieces', [StockController::class, 'lastPieces'])->name('inventory.admin.stock.last-pieces');
        Route::post('stock/counts-on-stock', [StockController::class, 'countsOnStock'])->name('inventory.admin.stock.counts-on-stock');

        Route::get('low-stock', [LowStockController::class, 'index'])->name('inventory.admin.low-stock');
    });
