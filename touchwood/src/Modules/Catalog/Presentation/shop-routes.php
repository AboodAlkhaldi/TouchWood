<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Presentation\Http\Controller\ShopPagesController;

/*
| Catalog's pages in the shop (catalog.md §4.5; owner, 2026-10-07, #8), under the store and the
| language, named storefront.catalog.*. Mounted into the area App\Http\StorefrontArea names, as
| Platform's store home and Access's sign-in pages are: anyone may read them, a guest included.
*/

Route::prefix('{store}/{locale}')
    ->middleware(StorefrontArea::MIDDLEWARE)
    ->name('storefront.catalog.')
    ->group(function (): void {
        Route::get('categories/{slug}', [ShopPagesController::class, 'category'])->name('category');
        Route::get('brands/{slug}', [ShopPagesController::class, 'brand'])->name('brand');
        Route::get('products/{slug}', [ShopPagesController::class, 'product'])->name('product');
        Route::get('search', [ShopPagesController::class, 'search'])->name('search');
        // As the shopper types: JSON, never logged (amendment 5(e)).
        Route::get('search/suggest', [ShopPagesController::class, 'suggest'])->name('suggest');
    });
