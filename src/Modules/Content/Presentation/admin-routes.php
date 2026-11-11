<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Content\Presentation\Http\Controller\BannersController;
use Modules\Content\Presentation\Http\Controller\BarsController;
use Modules\Content\Presentation\Http\Controller\BlogCategoriesController;
use Modules\Content\Presentation\Http\Controller\CampaignRunController;
use Modules\Content\Presentation\Http\Controller\DocumentsController;
use Modules\Content\Presentation\Http\Controller\HomePageController;
use Modules\Content\Presentation\Http\Controller\PopupsController;
use Modules\Content\Presentation\Http\Controller\RedirectsController;
use Modules\Content\Presentation\Http\Controller\SeoController;
use Modules\Content\Presentation\Http\Controller\ThemePreviewController;
use Modules\Content\Presentation\Http\Controller\TranslateController;
use Modules\Content\Presentation\Http\Controller\UploadsController;

/*
| Content's routes in the admin panel (content.md §4.3): Translate, which every Content editor calls (§1.3), the upload
| boxes' images, and the screens' pages, each with its own reads and changes (PR 7).
|
| Mounted into the area App\Http\AdminArea names, as Sync's are. Who may do any of this is Content's answer,
| never the routing's: each handler asks for its permission in the store (§3).
|
| Named content.admin.*, which the panel's route list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::post('content/translate', TranslateController::class)->name('content.admin.translate');
        // S8's link (ops.md §9.7 #3): a run's page is its campaign's (W4b, PR 8).
        Route::get('content/campaigns/runs/{run}', CampaignRunController::class)->name('content.admin.campaigns.run');
        // Preview Theme's signed cookie for this browser (W5b); the shop draws it with its frame (PR 9).
        Route::delete('content/themes/preview', [ThemePreviewController::class, 'stop'])->name('content.admin.themes.preview.stop');
        Route::post('content/themes/{theme}/preview', [ThemePreviewController::class, 'start'])->name('content.admin.themes.preview');
        Route::post('content/uploads/{kind}', UploadsController::class)->name('content.admin.upload');

        // W1 · Home Page.
        Route::get('content/home', [HomePageController::class, 'index'])->name('content.admin.home');
        Route::post('content/home/sections', [HomePageController::class, 'add'])->name('content.admin.home.add');
        Route::post('content/home/sections/order', [HomePageController::class, 'order'])->name('content.admin.home.order');
        Route::post('content/home/sections/{section}/show', [HomePageController::class, 'show'])->name('content.admin.home.show');
        Route::post('content/home/sections/{section}/remove', [HomePageController::class, 'remove'])->name('content.admin.home.remove');
        Route::post('content/home/footer', [HomePageController::class, 'saveFooter'])->name('content.admin.home.footer');
        Route::get('content/home/{section}', [HomePageController::class, 'edit'])->name('content.admin.home.edit');
        Route::post('content/home/{section}', [HomePageController::class, 'fill'])->name('content.admin.home.fill');

        // W2 · Bars.
        Route::get('content/bars', [BarsController::class, 'index'])->name('content.admin.bars');
        Route::post('content/bars/announcement', [BarsController::class, 'saveBar'])->name('content.admin.bars.save');
        Route::post('content/bars/smart', [BarsController::class, 'saveSmartBar'])->name('content.admin.smart-bars.add');
        Route::post('content/bars/smart/{smartBar}', [BarsController::class, 'saveSmartBar'])->name('content.admin.smart-bars.save');
        Route::post('content/bars/smart/{smartBar}/delete', [BarsController::class, 'deleteSmartBar'])->name('content.admin.smart-bars.delete');

        // W3 · Banners.
        Route::get('content/banners', [BannersController::class, 'index'])->name('content.admin.banners');
        Route::get('content/banners/new', [BannersController::class, 'create'])->name('content.admin.banners.create');
        Route::post('content/banners', [BannersController::class, 'store'])->name('content.admin.banners.store');
        Route::post('content/banners/order', [BannersController::class, 'order'])->name('content.admin.banners.order');
        Route::get('content/banners/{banner}', [BannersController::class, 'edit'])->name('content.admin.banners.edit');
        Route::post('content/banners/{banner}', [BannersController::class, 'update'])->name('content.admin.banners.update');
        Route::post('content/banners/{banner}/end', [BannersController::class, 'end'])->name('content.admin.banners.end');
        Route::post('content/banners/{banner}/draft', [BannersController::class, 'draft'])->name('content.admin.banners.draft');
        Route::post('content/banners/{banner}/delete', [BannersController::class, 'delete'])->name('content.admin.banners.delete');

        // W7 · Pages, W8 · Blog (posts and categories), W9 · Projects: one controller, each route naming its kind. The
        // product searches come first: `products` is no item's id.
        Route::get('content/blog/products', [DocumentsController::class, 'products'])->name('content.admin.posts.products')->defaults('kind', 'post');
        Route::get('content/projects/products', [DocumentsController::class, 'products'])->name('content.admin.projects.products')->defaults('kind', 'project');
        Route::get('content/blog/categories', [BlogCategoriesController::class, 'index'])->name('content.admin.blog.categories');
        Route::post('content/blog/categories', [BlogCategoriesController::class, 'save'])->name('content.admin.blog.categories.add');
        Route::post('content/blog/categories/order', [BlogCategoriesController::class, 'order'])->name('content.admin.blog.categories.order');
        Route::post('content/blog/categories/{category}', [BlogCategoriesController::class, 'save'])->name('content.admin.blog.categories.save');
        Route::post('content/blog/categories/{category}/delete', [BlogCategoriesController::class, 'delete'])->name('content.admin.blog.categories.delete');

        foreach (['page' => 'content/pages', 'post' => 'content/blog/posts', 'project' => 'content/projects'] as $kind => $path) {
            $name = "content.admin.{$kind}s";
            Route::get($kind === 'post' ? 'content/blog' : $path, [DocumentsController::class, 'index'])->name($kind === 'post' ? 'content.admin.blog' : $name)->defaults('kind', $kind);
            Route::get("{$path}/new", [DocumentsController::class, 'create'])->name("{$name}.create")->defaults('kind', $kind);
            Route::post($path, [DocumentsController::class, 'store'])->name("{$name}.store")->defaults('kind', $kind);
            Route::get("{$path}/{document}", [DocumentsController::class, 'edit'])->name("{$name}.edit")->defaults('kind', $kind);
            Route::post("{$path}/{document}", [DocumentsController::class, 'update'])->name("{$name}.update")->defaults('kind', $kind);
            Route::post("{$path}/{document}/unpublish", [DocumentsController::class, 'unpublish'])->name("{$name}.unpublish")->defaults('kind', $kind);
            Route::post("{$path}/{document}/delete", [DocumentsController::class, 'delete'])->name("{$name}.delete")->defaults('kind', $kind);
        }

        // W10 · SEO.
        Route::get('content/seo', [SeoController::class, 'index'])->name('content.admin.seo');
        Route::post('content/seo', [SeoController::class, 'save'])->name('content.admin.seo.save');
        Route::post('content/seo/sitemap', [SeoController::class, 'rebuild'])->name('content.admin.seo.sitemap');

        // W11 · Redirects, and W11b the checked sheet. A line is named in the body: an address holds slashes.
        Route::get('content/redirects', [RedirectsController::class, 'index'])->name('content.admin.redirects');
        Route::post('content/redirects', [RedirectsController::class, 'add'])->name('content.admin.redirects.add');
        Route::post('content/redirects/change', [RedirectsController::class, 'change'])->name('content.admin.redirects.change');
        Route::post('content/redirects/reset', [RedirectsController::class, 'reset'])->name('content.admin.redirects.reset');
        Route::post('content/redirects/final', [RedirectsController::class, 'final'])->name('content.admin.redirects.final');
        Route::post('content/redirects/delete', [RedirectsController::class, 'delete'])->name('content.admin.redirects.delete');
        Route::post('content/redirects/check', [RedirectsController::class, 'check'])->name('content.admin.redirects.check');
        Route::post('content/redirects/sheets', [RedirectsController::class, 'upload'])->name('content.admin.redirects.upload');
        Route::get('content/redirects/sheets/{upload}', [RedirectsController::class, 'sheet'])->name('content.admin.redirects.sheet');
        Route::post('content/redirects/sheets/{upload}/apply', [RedirectsController::class, 'apply'])->name('content.admin.redirects.apply');

        // W6 · Pop-Ups.
        Route::get('content/popups', [PopupsController::class, 'index'])->name('content.admin.popups');
        Route::get('content/popups/new', [PopupsController::class, 'create'])->name('content.admin.popups.create');
        Route::post('content/popups', [PopupsController::class, 'store'])->name('content.admin.popups.store');
        Route::get('content/popups/{popup}', [PopupsController::class, 'edit'])->name('content.admin.popups.edit');
        Route::post('content/popups/{popup}', [PopupsController::class, 'update'])->name('content.admin.popups.update');
        Route::post('content/popups/{popup}/end', [PopupsController::class, 'end'])->name('content.admin.popups.end');
        Route::post('content/popups/{popup}/draft', [PopupsController::class, 'draft'])->name('content.admin.popups.draft');
        Route::post('content/popups/{popup}/delete', [PopupsController::class, 'delete'])->name('content.admin.popups.delete');
    });
