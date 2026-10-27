<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Sales\Presentation\Http\Controller\OrdersController;
use Modules\Sales\Presentation\Http\Controller\ReturnsController;

/*
| Sales's screens in the admin panel (sales.md §4.1): Orders and an order's page, its packing slip, its
| transfer receipts and every change the page makes; Returns and a return's page.
|
| Mounted into the area App\Http\AdminArea names, as the other modules' screens are. Who may open any of
| these is Sales's answer, never the routing's. Named sales.admin.*, which the panel's route list carries
| (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('orders', [OrdersController::class, 'index'])->name('sales.admin.orders');
        // A receipt's file, named before an order's own routes take the word.
        Route::get('orders/files/{media}', [OrdersController::class, 'file'])->name('sales.admin.orders.file');
        Route::get('orders/{order}', [OrdersController::class, 'show'])->name('sales.admin.orders.show');
        Route::get('orders/{order}/print', [OrdersController::class, 'print'])->name('sales.admin.orders.print');
        Route::get('orders/{order}/edit', [OrdersController::class, 'edit'])->name('sales.admin.orders.edit');
        Route::get('orders/{order}/edit/sizes', [OrdersController::class, 'editSizes'])->name('sales.admin.orders.edit.sizes');
        Route::get('orders/{order}/edit/preview', [OrdersController::class, 'editPreview'])->name('sales.admin.orders.edit.preview');
        Route::post('orders/{order}/edit', [OrdersController::class, 'saveEdit'])->name('sales.admin.orders.edit.save');
        Route::post('orders/{order}/approve', [OrdersController::class, 'approve'])->name('sales.admin.orders.approve');
        Route::post('orders/{order}/reject', [OrdersController::class, 'reject'])->name('sales.admin.orders.reject');
        Route::post('orders/{order}/cancel', [OrdersController::class, 'cancel'])->name('sales.admin.orders.cancel');
        Route::post('orders/{order}/ship', [OrdersController::class, 'ship'])->name('sales.admin.orders.ship');
        Route::post('orders/{order}/deliver', [OrdersController::class, 'deliver'])->name('sales.admin.orders.deliver');
        Route::post('orders/{order}/came-back', [OrdersController::class, 'cameBack'])->name('sales.admin.orders.came-back');
        Route::post('orders/{order}/tick', [OrdersController::class, 'tick'])->name('sales.admin.orders.tick');
        Route::post('orders/{order}/ship-unpaid', [OrdersController::class, 'shipUnpaid'])->name('sales.admin.orders.ship-unpaid');
        Route::post('orders/{order}/payment', [OrdersController::class, 'payment'])->name('sales.admin.orders.payment');
        Route::post('orders/{order}/refund', [OrdersController::class, 'refund'])->name('sales.admin.orders.refund');
        Route::post('orders/{order}/void', [OrdersController::class, 'void'])->name('sales.admin.orders.void');
        Route::post('orders/{order}/clear-short', [OrdersController::class, 'clearShort'])->name('sales.admin.orders.clear-short');

        Route::get('returns', [ReturnsController::class, 'index'])->name('sales.admin.returns');
        Route::get('returns/{return}', [ReturnsController::class, 'show'])->name('sales.admin.returns.show');
        Route::post('returns/{return}/accept', [ReturnsController::class, 'accept'])->name('sales.admin.returns.accept');
        Route::post('returns/{return}/refuse', [ReturnsController::class, 'refuse'])->name('sales.admin.returns.refuse');
        Route::post('returns/{return}/receive', [ReturnsController::class, 'receive'])->name('sales.admin.returns.receive');
        Route::post('returns/{return}/close', [ReturnsController::class, 'close'])->name('sales.admin.returns.close');
        Route::post('returns/{return}/complete', [ReturnsController::class, 'complete'])->name('sales.admin.returns.complete');
    });
