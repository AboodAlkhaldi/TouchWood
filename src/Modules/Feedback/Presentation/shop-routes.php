<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Feedback\Presentation\Http\Controller\MyFeedbackController;
use Modules\Feedback\Presentation\Http\Controller\ShopFeedbackController;

/*
| Feedback in the shop (feedback.md §4.1 F5, F6, F8), under the store and language as every shop page
| is. Writing, asking, removing and the account's pages are for signed-in customers; Save takes a guest
| too, who is sent to sign in and comes back with it saved (owner's Q18 (a)). No route names an account:
| each handler reads who is asking. `{product}` is the product's id, as the page carries it.
|
| Named storefront.* so the shop's pages carry them and the panel's never do (frontend.md §1.4).
*/
Route::prefix('{store}/{locale}')
    ->middleware(StorefrontArea::MIDDLEWARE)
    ->group(function (): void {
        Route::post('products/{product}/save', [ShopFeedbackController::class, 'save'])->name('storefront.feedback.save');

        Route::middleware(StorefrontArea::SIGNED_IN)->group(function (): void {
            Route::post('products/{product}/reviews', [ShopFeedbackController::class, 'review'])->name('storefront.feedback.review');
            Route::post('products/{product}/questions', [ShopFeedbackController::class, 'ask'])->name('storefront.feedback.ask');
            Route::post('products/{product}/remove', [ShopFeedbackController::class, 'remove'])->name('storefront.feedback.remove');
            Route::get('products/{product}/save-after-sign-in', [ShopFeedbackController::class, 'saveAfterSignIn'])->name('storefront.feedback.save-after-sign-in');

            Route::get('account/reviews', [MyFeedbackController::class, 'reviews'])->name('storefront.feedback.reviews');
            Route::get('account/questions', [MyFeedbackController::class, 'questions'])->name('storefront.feedback.questions');
            Route::get('account/wishlist', [MyFeedbackController::class, 'wishlist'])->name('storefront.feedback.wishlist');
        });
    });
