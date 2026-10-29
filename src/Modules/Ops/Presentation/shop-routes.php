<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Ops\Presentation\Http\Controller\BellController;

/*
| The shop's bell (ops.md §1.2, §4), under the store and language as every shop page is, for a
| signed-in customer: their own entries in the store being shopped. No route names an account.
|
| Named storefront.* so the shop's pages carry them and the panel's never do (frontend.md §1.4).
*/
Route::prefix('{store}/{locale}')
    ->middleware([...StorefrontArea::MIDDLEWARE, StorefrontArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('account/notifications', [BellController::class, 'index'])->name('storefront.ops.bell');
        Route::get('account/notifications/unread', [BellController::class, 'unread'])->name('storefront.ops.bell.unread');
        // Every entry, named before one entry's own route takes the word.
        Route::post('account/notifications/read-all', [BellController::class, 'readAll'])->name('storefront.ops.bell.read-all');
        Route::post('account/notifications/{entry}/read', [BellController::class, 'read'])->name('storefront.ops.bell.read');
    });
