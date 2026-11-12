<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Content\Presentation\Http\Controller\ShopPagesController;
use Modules\Content\Presentation\Http\Controller\ThemePreviewController;

/*
| Content's addresses under a store in the shop (content.md §4.5): the store's home - `storefront.home`, Platform's
| placeholder until now (§2.3 row L4) - and Content's own pages, H2 to H7. A blog category's address sits before a
| post's, so a post may still be called "categories". Platform's not-found fallback stays the last route of all.
|
| And **Stop Preview** on the preview's line (W5b): it only forgets this browser's preview cookie, so it needs no one
| signed in. Unnamed, as the layout writes it out: it stays out of every shop page's route list (Ziggy's storefront
| group).
*/

Route::prefix('{store}/{locale}')
    ->middleware(StorefrontArea::MIDDLEWARE)
    ->group(function (): void {
        Route::get('/', [ShopPagesController::class, 'home'])->name('storefront.home');
        Route::get('pages/{slug}', [ShopPagesController::class, 'page'])->name('storefront.content.page');
        Route::get('blog', [ShopPagesController::class, 'blog'])->name('storefront.content.blog');
        Route::get('blog/categories/{slug}', [ShopPagesController::class, 'blogCategory'])->name('storefront.content.blog-category');
        Route::get('blog/{slug}', [ShopPagesController::class, 'post'])->name('storefront.content.post');
        Route::get('projects', [ShopPagesController::class, 'projects'])->name('storefront.content.projects');
        Route::get('projects/{slug}', [ShopPagesController::class, 'project'])->name('storefront.content.project');
        Route::get('campaigns/{slug}', [ShopPagesController::class, 'campaign'])->name('storefront.content.campaign');

        Route::post('theme-preview/stop', [ThemePreviewController::class, 'stopOnShop']);
    });
