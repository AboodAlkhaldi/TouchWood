<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Payments\Presentation\Http\Controller\GatewayController;

/*
| Payments' screens in the admin panel (payments.md §4.2): the Gateway page; the Payments list and an attempt
| join it with their steps. Mounted into the area App\Http\AdminArea names, as the other modules' screens are.
| Who may open any of these is Payments' answer, never the routing's.
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('payments/gateway', [GatewayController::class, 'index'])->name('payments.admin.gateway');
        Route::post('payments/gateway/keys', [GatewayController::class, 'keys'])->name('payments.admin.gateway.keys');
        Route::post('payments/gateway/mode', [GatewayController::class, 'mode'])->name('payments.admin.gateway.mode');
        Route::post('payments/gateway/switch', [GatewayController::class, 'switch'])->name('payments.admin.gateway.switch');
        Route::post('payments/gateway/apple-pay', [GatewayController::class, 'applePay'])->name('payments.admin.gateway.apple-pay');
    });
