<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Sales\Presentation\Http\Controller\ShopCartController;
use Modules\Sales\Presentation\Http\Controller\ShopCheckoutController;
use Modules\Sales\Presentation\Http\Controller\ShopOrdersController;

/*
| Sales in the shop (sales.md §4.1 S2–S4), under the store and language as every shop page is. The cart is a
| guest's too (§1.1): each handler reads who is asking - a customer, or the guest the browser keeps - and no
| route names an account. The coupon is a customer's only; its handler says so to a guest.
|
| Named storefront.* so the shop's pages carry them and the panel's never do (frontend.md §1.4).
*/
Route::prefix('{store}/{locale}')
    ->middleware(StorefrontArea::MIDDLEWARE)
    ->group(function (): void {
        Route::get('cart', [ShopCartController::class, 'cart'])->name('storefront.sales.cart');
        Route::post('cart/lines', [ShopCartController::class, 'add'])->name('storefront.sales.cart.add');
        Route::post('cart/lines/change', [ShopCartController::class, 'change'])->name('storefront.sales.cart.change');
        Route::post('cart/lines/remove', [ShopCartController::class, 'remove'])->name('storefront.sales.cart.remove');
        Route::get('cart/sign-in', [ShopCartController::class, 'signIn'])->name('storefront.sales.cart.sign-in');
        Route::post('cart/coupon', [ShopCartController::class, 'coupon'])->name('storefront.sales.cart.coupon');

        // Checkout and the order placed (S5, S6): a customer's only.
        Route::middleware(StorefrontArea::SIGNED_IN)->group(function (): void {
            Route::get('checkout', [ShopCheckoutController::class, 'checkout'])->name('storefront.sales.checkout');
            Route::post('checkout/place', [ShopCheckoutController::class, 'place'])->name('storefront.sales.place');
            Route::get('orders/{number}/placed', [ShopCheckoutController::class, 'placed'])->name('storefront.sales.placed');
            Route::post('orders/{number}/receipts', [ShopCheckoutController::class, 'receipt'])->name('storefront.sales.receipt');

            // The account's orders and returns (S7–S10).
            Route::get('account/orders', [ShopOrdersController::class, 'orders'])->name('storefront.sales.orders');
            Route::get('account/orders/{number}', [ShopOrdersController::class, 'order'])->name('storefront.sales.order');
            Route::post('account/orders/{number}/cancel', [ShopOrdersController::class, 'cancel'])->name('storefront.sales.order.cancel');
            Route::post('account/orders/{number}/buy-again', [ShopOrdersController::class, 'buyAgain'])->name('storefront.sales.order.buy-again');
            Route::get('account/orders/{number}/return', [ShopOrdersController::class, 'askReturn'])->name('storefront.sales.order.ask-return');
            Route::post('account/orders/{number}/return', [ShopOrdersController::class, 'requestReturn'])->name('storefront.sales.order.request-return');
            Route::get('account/returns/{return}', [ShopOrdersController::class, 'returned'])->name('storefront.sales.return');
            Route::post('account/returns/{return}/withdraw', [ShopOrdersController::class, 'withdraw'])->name('storefront.sales.return.withdraw');
        });
    });
