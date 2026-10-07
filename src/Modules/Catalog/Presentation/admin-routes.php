<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Presentation\Http\Controller\AttributesController;
use Modules\Catalog\Presentation\Http\Controller\BrandsController;
use Modules\Catalog\Presentation\Http\Controller\CategoriesController;
use Modules\Catalog\Presentation\Http\Controller\LabelsController;
use Modules\Catalog\Presentation\Http\Controller\ProductChangesController;
use Modules\Catalog\Presentation\Http\Controller\ProductsController;
use Modules\Catalog\Presentation\Http\Controller\SearchWordsController;
use Modules\Catalog\Presentation\Http\Controller\VariationsController;
use Modules\Catalog\Presentation\Http\Controller\WarrantiesController;

/*
| Catalog's screens in the admin panel (catalog.md §4.4, amendment 13): the six shared lists first
| (step 1), then the products (step 2) — brands, categories, attributes and their values, variations, labels, warranties, and the
| search words with the searches that found nothing.
|
| Mounted into the area App\Http\AdminArea names, as Platform's and B2B's screens are. Who may open
| any of these is Catalog's answer, never the routing's: each read asks for the list's job in some
| store, each change for it with All stores (§1.5–§1.11), and a store's menu order for its job in
| that store.
|
| Named catalog.admin.*, which the panel's route list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('brands', [BrandsController::class, 'index'])->name('catalog.admin.brands');
        Route::post('brands', [BrandsController::class, 'add'])->name('catalog.admin.brands.add');
        Route::post('brands/{brand}', [BrandsController::class, 'edit'])->name('catalog.admin.brands.edit');
        Route::post('brands/{brand}/default', [BrandsController::class, 'makeDefault'])->name('catalog.admin.brands.default');
        Route::post('brands/{brand}/activate', [BrandsController::class, 'activate'])->name('catalog.admin.brands.activate');
        Route::post('brands/{brand}/deactivate', [BrandsController::class, 'deactivate'])->name('catalog.admin.brands.deactivate');
        Route::post('brands/{brand}/delete', [BrandsController::class, 'delete'])->name('catalog.admin.brands.delete');

        Route::get('categories', [CategoriesController::class, 'index'])->name('catalog.admin.categories');
        Route::post('categories', [CategoriesController::class, 'add'])->name('catalog.admin.categories.add');
        // A store's menu order: the store the page shows, sent with the order (catalog.md §1.5).
        Route::post('categories/order', [CategoriesController::class, 'rank'])->name('catalog.admin.categories.rank');
        Route::post('categories/{category}', [CategoriesController::class, 'edit'])->name('catalog.admin.categories.edit');
        Route::post('categories/{category}/move', [CategoriesController::class, 'move'])->name('catalog.admin.categories.move');
        Route::post('categories/{category}/activate', [CategoriesController::class, 'activate'])->name('catalog.admin.categories.activate');
        Route::post('categories/{category}/deactivate', [CategoriesController::class, 'deactivate'])->name('catalog.admin.categories.deactivate');
        Route::post('categories/{category}/delete', [CategoriesController::class, 'delete'])->name('catalog.admin.categories.delete');

        Route::get('attributes', [AttributesController::class, 'index'])->name('catalog.admin.attributes');
        Route::post('attributes', [AttributesController::class, 'add'])->name('catalog.admin.attributes.add');
        Route::get('attributes/{attribute}', [AttributesController::class, 'show'])->name('catalog.admin.attributes.show');
        Route::post('attributes/{attribute}', [AttributesController::class, 'edit'])->name('catalog.admin.attributes.edit');
        Route::post('attributes/{attribute}/activate', [AttributesController::class, 'activate'])->name('catalog.admin.attributes.activate');
        Route::post('attributes/{attribute}/deactivate', [AttributesController::class, 'deactivate'])->name('catalog.admin.attributes.deactivate');
        Route::post('attributes/{attribute}/delete', [AttributesController::class, 'delete'])->name('catalog.admin.attributes.delete');
        Route::post('attributes/{attribute}/values', [AttributesController::class, 'addValue'])->name('catalog.admin.attributes.values.add');
        Route::post('attribute-values/{value}', [AttributesController::class, 'editValue'])->name('catalog.admin.values.edit');
        Route::post('attribute-values/{value}/activate', [AttributesController::class, 'activateValue'])->name('catalog.admin.values.activate');
        Route::post('attribute-values/{value}/deactivate', [AttributesController::class, 'deactivateValue'])->name('catalog.admin.values.deactivate');
        Route::post('attribute-values/{value}/delete', [AttributesController::class, 'deleteValue'])->name('catalog.admin.values.delete');

        Route::get('variations', [VariationsController::class, 'index'])->name('catalog.admin.variations');
        Route::post('variations', [VariationsController::class, 'add'])->name('catalog.admin.variations.add');
        Route::post('variations/{set}', [VariationsController::class, 'edit'])->name('catalog.admin.variations.edit');
        Route::post('variations/{set}/activate', [VariationsController::class, 'activate'])->name('catalog.admin.variations.activate');
        Route::post('variations/{set}/deactivate', [VariationsController::class, 'deactivate'])->name('catalog.admin.variations.deactivate');
        Route::post('variations/{set}/delete', [VariationsController::class, 'delete'])->name('catalog.admin.variations.delete');

        Route::get('labels', [LabelsController::class, 'index'])->name('catalog.admin.labels');
        Route::post('labels', [LabelsController::class, 'add'])->name('catalog.admin.labels.add');
        Route::post('labels/{label}', [LabelsController::class, 'edit'])->name('catalog.admin.labels.edit');
        Route::post('labels/{label}/activate', [LabelsController::class, 'activate'])->name('catalog.admin.labels.activate');
        Route::post('labels/{label}/deactivate', [LabelsController::class, 'deactivate'])->name('catalog.admin.labels.deactivate');
        Route::post('labels/{label}/delete', [LabelsController::class, 'delete'])->name('catalog.admin.labels.delete');

        Route::get('warranties', [WarrantiesController::class, 'index'])->name('catalog.admin.warranties');
        Route::post('warranties', [WarrantiesController::class, 'add'])->name('catalog.admin.warranties.add');
        Route::post('warranties/{warranty}', [WarrantiesController::class, 'edit'])->name('catalog.admin.warranties.edit');
        Route::post('warranties/{warranty}/activate', [WarrantiesController::class, 'activate'])->name('catalog.admin.warranties.activate');
        Route::post('warranties/{warranty}/deactivate', [WarrantiesController::class, 'deactivate'])->name('catalog.admin.warranties.deactivate');
        Route::post('warranties/{warranty}/delete', [WarrantiesController::class, 'delete'])->name('catalog.admin.warranties.delete');

        Route::get('search-words', [SearchWordsController::class, 'index'])->name('catalog.admin.search-words');
        Route::post('search-words', [SearchWordsController::class, 'add'])->name('catalog.admin.search-words.add');
        Route::post('search-words/{pair}/delete', [SearchWordsController::class, 'delete'])->name('catalog.admin.search-words.delete');

        // The products (step 2): the list, a new draft, and one product's page with each tab's changes.
        Route::get('products', [ProductsController::class, 'index'])->name('catalog.admin.products');
        Route::post('products', [ProductsController::class, 'create'])->name('catalog.admin.products.create');
        Route::get('products/{product}', [ProductsController::class, 'show'])->name('catalog.admin.products.show');
        Route::post('products/{product}/details', [ProductChangesController::class, 'details'])->name('catalog.admin.products.details');
        Route::post('products/{product}/gallery', [ProductChangesController::class, 'gallery'])->name('catalog.admin.products.gallery');
        Route::post('products/{product}/search-words', [ProductChangesController::class, 'searchWords'])->name('catalog.admin.products.search-words');
        Route::post('products/{product}/filters', [ProductChangesController::class, 'filters'])->name('catalog.admin.products.filters');
        Route::post('products/{product}/related/{kind}', [ProductChangesController::class, 'related'])->name('catalog.admin.products.related');
        Route::post('products/{product}/ready', [ProductChangesController::class, 'ready'])->name('catalog.admin.products.ready');
        Route::post('products/{product}/archive', [ProductChangesController::class, 'archive'])->name('catalog.admin.products.archive');
        Route::post('products/{product}/restore', [ProductChangesController::class, 'restore'])->name('catalog.admin.products.restore');
        Route::post('products/{product}/delete', [ProductChangesController::class, 'delete'])->name('catalog.admin.products.delete');
        Route::post('products/{product}/variants', [ProductChangesController::class, 'addVariant'])->name('catalog.admin.products.variants.add');
        Route::post('products/{product}/variants/{variant}', [ProductChangesController::class, 'editVariant'])->name('catalog.admin.products.variants.edit');
        Route::post('products/{product}/variants/{variant}/code', [ProductChangesController::class, 'correctCode'])->name('catalog.admin.products.variants.code');
        Route::post('products/{product}/variants/{variant}/photos', [ProductChangesController::class, 'variantPhotos'])->name('catalog.admin.products.variants.photos');
        Route::post('products/{product}/variants/{variant}/archive', [ProductChangesController::class, 'archiveVariant'])->name('catalog.admin.products.variants.archive');
        Route::post('products/{product}/variants/{variant}/restore', [ProductChangesController::class, 'restoreVariant'])->name('catalog.admin.products.variants.restore');
        Route::post('products/{product}/variants/{variant}/delete', [ProductChangesController::class, 'deleteVariant'])->name('catalog.admin.products.variants.delete');
    });
