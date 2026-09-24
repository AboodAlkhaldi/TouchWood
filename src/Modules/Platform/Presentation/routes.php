<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Presentation\Http\Controller\ChooseStoreController;
use Modules\Platform\Presentation\Http\Controller\StoreHomeController;
use Modules\Platform\Presentation\Http\Controller\StoreWithoutLanguageController;

/*
| The shop's front door: the country page, and the store home behind it.
|
| Mounted into the area App\Http\StorefrontArea names, the same way Platform's panel screens are
| mounted into the admin's. The shop's session cookie is set before "web" opens a session, so the
| whole list is applied here rather than wrapped in "web" from outside (frontend.md 2.3).
*/

// brand.com: the country page, which is the one shop page that belongs to no store - so it is the
// one that must not ask which store its URL names.
Route::middleware(StorefrontArea::MIDDLEWARE_WITHOUT_STORE)
    ->group(function (): void {
        Route::get('/', ChooseStoreController::class)->name('platform.choose-store');
    });

// brand.com/sa: on to the remembered language, or the default. A redirect and nothing else, so it
// needs no session and no page of its own.
Route::middleware('web')->group(function (): void {
    Route::get('{store}', StoreWithoutLanguageController::class)->name('storefront.store');
});

// Every storefront page lives under brand.com/{store}/{locale}/...
Route::prefix('{store}/{locale}')
    ->middleware(StorefrontArea::MIDDLEWARE)
    ->group(function (): void {
        Route::get('/', StoreHomeController::class)->name('storefront.home');
    });
