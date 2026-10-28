<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Platform\Presentation\Http\Controller\ChooseStoreController;
use Modules\Platform\Presentation\Http\Controller\ShopNotFoundController;
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

// brand.com/sa: on to the remembered language, or the default. A redirect and nothing else, but
// `web` opens the shop's session cookie here all the same, so it takes the shop's session first:
// under the framework's two-hour fallback this route signed remembered customers out, and its
// sweep deleted every shop session idle that long (security audit 2026-10-27).
Route::middleware([StorefrontArea::SESSION, 'web'])->group(function (): void {
    Route::get('{store}', StoreWithoutLanguageController::class)->name('storefront.store');
});

// Every storefront page lives under brand.com/{store}/{locale}/...
Route::prefix('{store}/{locale}')
    ->middleware(StorefrontArea::MIDDLEWARE)
    ->group(function (): void {
        Route::get('/', StoreHomeController::class)->name('storefront.home');

        // An address under a store that no page answers, read: the shop's own not-found page (owner,
        // 2026-10-20; frontend.md §2.6) - after every other route, whichever module's. {store} never
        // matches a reserved path (/admin, /build, /api...), so this never catches one.
        Route::fallback(ShopNotFoundController::class)->where('fallbackPlaceholder', ShopNotFoundController::PATHS);
    });

// Anything but a read sent to such an address stays not found, as before - Laravel's own page, no session
// opened. Without this, the read above would turn it into "method not allowed".
Route::prefix('{store}/{locale}')->group(function (): void {
    Route::match(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], '{fallbackPlaceholder}', ShopNotFoundController::class)
        ->where('fallbackPlaceholder', ShopNotFoundController::PATHS)
        ->fallback();
});
