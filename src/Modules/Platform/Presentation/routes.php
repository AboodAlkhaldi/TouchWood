<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Platform\Presentation\Http\Controller\ChooseStoreController;
use Modules\Platform\Presentation\Http\Controller\StoreHomeController;
use Modules\Platform\Presentation\Http\Controller\StoreWithoutLanguageController;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;

Route::get('/', ChooseStoreController::class)->name('platform.choose-store');

// brand.com/sa: on to the remembered language, or the default.
Route::get('{store}', StoreWithoutLanguageController::class)->name('storefront.store');

// Every storefront page lives under brand.com/{store}/{locale}/...
Route::prefix('{store}/{locale}')
    ->middleware(ResolveStore::ALIAS)
    ->group(function () {
        Route::get('/', StoreHomeController::class)->name('storefront.home');
    });
