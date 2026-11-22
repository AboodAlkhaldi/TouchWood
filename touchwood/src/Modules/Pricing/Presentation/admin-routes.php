<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Pricing\Presentation\Http\Controller\CategoryDiscountsController;
use Modules\Pricing\Presentation\Http\Controller\NeedsAPriceController;
use Modules\Pricing\Presentation\Http\Controller\OffersController;
use Modules\Pricing\Presentation\Http\Controller\PricesController;
use Modules\Pricing\Presentation\Http\Controller\WholesalePricesController;

/*
| Pricing's screens in the admin panel (pricing.md §4.3.2, stage 5's screens step 3): Prices, Offers (the
| panel's word for a sale, §9.4), Category Discounts and a discount's page, Wholesale Prices and Needs a
| Price - with the reads their dialogs ask for as typed (JSON: the offer's note, the discount's preview, the
| pickers) and every change.
|
| Mounted into the area App\Http\AdminArea names, as Catalog's screens are. Who may open or do any of
| these is Pricing's answer, never the routing's: each read and each handler asks for its own job in the
| store (§3).
|
| Named pricing.admin.*, which the panel's route list carries (config/ziggy.php).
*/

// An offer's or a discount's id: a ULID, so the words of the routes beside it are never taken for one.
$id = '[0-9A-Za-z]{26}';

Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function () use ($id): void {
        Route::get('prices', [PricesController::class, 'index'])->name('pricing.admin.prices');
        Route::post('prices', [PricesController::class, 'save'])->name('pricing.admin.prices.save');
        Route::post('prices/remove', [PricesController::class, 'remove'])->name('pricing.admin.prices.remove');
        Route::get('prices/size', [PricesController::class, 'size'])->name('pricing.admin.prices.size');

        Route::get('offers', [OffersController::class, 'index'])->name('pricing.admin.offers');
        Route::post('offers', [OffersController::class, 'add'])->name('pricing.admin.offers.add');
        Route::get('offers/note', [OffersController::class, 'note'])->name('pricing.admin.offers.note');
        Route::get('offers/products', [OffersController::class, 'products'])->name('pricing.admin.offers.products');
        Route::get('offers/filter-products', [OffersController::class, 'filterProducts'])->name('pricing.admin.offers.filter-products');
        Route::get('offers/sizes', [OffersController::class, 'sizes'])->name('pricing.admin.offers.sizes');
        Route::get('offers/{sale}', [OffersController::class, 'show'])->where('sale', $id)->name('pricing.admin.offers.show');
        Route::post('offers/{sale}', [OffersController::class, 'change'])->where('sale', $id)->name('pricing.admin.offers.change');
        Route::post('offers/{sale}/end-date', [OffersController::class, 'changeEnd'])->where('sale', $id)->name('pricing.admin.offers.change-end');
        Route::post('offers/{sale}/end', [OffersController::class, 'end'])->where('sale', $id)->name('pricing.admin.offers.end');
        Route::post('offers/{sale}/remove', [OffersController::class, 'remove'])->where('sale', $id)->name('pricing.admin.offers.remove');

        Route::get('category-discounts', [CategoryDiscountsController::class, 'index'])->name('pricing.admin.category-discounts');
        Route::post('category-discounts', [CategoryDiscountsController::class, 'add'])->name('pricing.admin.category-discounts.add');
        Route::get('category-discounts/preview', [CategoryDiscountsController::class, 'preview'])->name('pricing.admin.category-discounts.preview');
        Route::get('category-discounts/categories', [CategoryDiscountsController::class, 'categories'])->name('pricing.admin.category-discounts.categories');
        Route::get('category-discounts/left-out', [CategoryDiscountsController::class, 'leftOut'])->name('pricing.admin.category-discounts.left-out');
        Route::get('category-discounts/{discount}', [CategoryDiscountsController::class, 'show'])->where('discount', $id)->name('pricing.admin.category-discounts.show');
        Route::post('category-discounts/{discount}', [CategoryDiscountsController::class, 'change'])->where('discount', $id)->name('pricing.admin.category-discounts.change');
        Route::post('category-discounts/{discount}/end-date', [CategoryDiscountsController::class, 'changeEnd'])->where('discount', $id)->name('pricing.admin.category-discounts.change-end');
        Route::post('category-discounts/{discount}/end', [CategoryDiscountsController::class, 'end'])->where('discount', $id)->name('pricing.admin.category-discounts.end');
        Route::post('category-discounts/{discount}/remove', [CategoryDiscountsController::class, 'remove'])->where('discount', $id)->name('pricing.admin.category-discounts.remove');

        Route::get('wholesale-prices', [WholesalePricesController::class, 'index'])->name('pricing.admin.wholesale-prices');
        Route::post('wholesale-prices', [WholesalePricesController::class, 'save'])->name('pricing.admin.wholesale-prices.save');
        Route::get('wholesale-prices/sizes', [WholesalePricesController::class, 'sizes'])->name('pricing.admin.wholesale-prices.sizes');

        Route::get('needs-a-price', [NeedsAPriceController::class, 'index'])->name('pricing.admin.needs-a-price');
    });
