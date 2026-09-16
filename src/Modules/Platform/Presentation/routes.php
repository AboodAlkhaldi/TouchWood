<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Platform\Presentation\Http\Controller\ChooseStoreController;
use Modules\Platform\Presentation\Http\Controller\StoreHomeController;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;

Route::get('/', ChooseStoreController::class)->name('platform.choose-store');

Route::prefix('{store}')
    ->middleware(ResolveStore::ALIAS)
    ->group(function () {
        Route::get('/', StoreHomeController::class)->name('storefront.home');
    });
