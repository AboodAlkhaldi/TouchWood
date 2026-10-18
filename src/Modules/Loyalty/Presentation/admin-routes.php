<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Loyalty\Presentation\Http\Controller\PointsAdminController;

/*
| Loyalty's screens in the admin panel (loyalty.md §4.1): the Points menu's Customers, Redemptions and
| Settings, and one customer's points with its change by hand.
|
| Mounted into the area App\Http\AdminArea names, as the other modules' screens are. Who may open any
| of these is Loyalty's answer, never the routing's. Named loyalty.admin.*, which the panel's route
| list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('points/customers', [PointsAdminController::class, 'customers'])->name('loyalty.admin.customers');
        Route::get('points/customers/{customer}', [PointsAdminController::class, 'customer'])->name('loyalty.admin.customer');
        Route::post('points/customers/{customer}/change', [PointsAdminController::class, 'change'])->name('loyalty.admin.customer.change');
        Route::get('points/redemptions', [PointsAdminController::class, 'redemptions'])->name('loyalty.admin.redemptions');
        Route::get('points/settings', [PointsAdminController::class, 'settings'])->name('loyalty.admin.settings');
    });
