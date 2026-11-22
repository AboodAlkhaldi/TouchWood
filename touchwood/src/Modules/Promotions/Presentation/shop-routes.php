<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Promotions\Presentation\Http\Controller\MyCouponsController;

/*
| The customer's own coupons (promotions.md §4.1 M5), inside their account and under the store and
| language, as every account page is. Signed-in customers only; a visitor is sent to the store home, as
| Access's account pages send one. No route carries an account: the handler reads who is asking.
|
| Named storefront.* so the shop's pages carry them and the panel's never do (frontend.md §1.4).
*/
Route::prefix('{store}/{locale}')
    ->middleware([...StorefrontArea::MIDDLEWARE, StorefrontArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('account/coupons', [MyCouponsController::class, 'show'])->name('storefront.promotions.coupons');
    });
