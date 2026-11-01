<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Ops\Presentation\Http\Controller\BellController;
use Modules\Ops\Presentation\Http\Controller\NewsletterController;
use Modules\Ops\Presentation\Http\Middleware\StopEmailStore;

/*
| The shop's bell (ops.md §1.2, §4), under the store and language as every shop page is, for a
| signed-in customer: their own entries in the store being shopped. No route names an account.
|
| Named storefront.* so the shop's pages carry them and the panel's never do (frontend.md §1.4).
*/
Route::prefix('{store}/{locale}')
    ->middleware([...StorefrontArea::MIDDLEWARE, StorefrontArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('account/notifications', [BellController::class, 'index'])->name('storefront.ops.bell');
        Route::get('account/notifications/unread', [BellController::class, 'unread'])->name('storefront.ops.bell.unread');
        // Every entry, named before one entry's own route takes the word.
        Route::post('account/notifications/read-all', [BellController::class, 'readAll'])->name('storefront.ops.bell.read-all');
        Route::post('account/notifications/{entry}/read', [BellController::class, 'read'])->name('storefront.ops.bell.read');
    });

/*
| The newsletter box and its links (ops.md §1.9, §1.3), open to anyone: the box is a guest's; the
| confirmation link carries its token.
*/
Route::prefix('{store}/{locale}')
    ->middleware(StorefrontArea::MIDDLEWARE)
    ->group(function (): void {
        Route::post('newsletter', [NewsletterController::class, 'subscribe'])->name('storefront.ops.newsletter.subscribe');
        Route::get('newsletter/confirm/{token}', [NewsletterController::class, 'confirmation'])->where('token', '[A-Za-z0-9]{1,128}')->name('storefront.ops.newsletter.confirm');
        Route::post('newsletter/confirm/{token}', [NewsletterController::class, 'confirm'])->where('token', '[A-Za-z0-9]{1,128}')->name('storefront.ops.newsletter.confirm.post');
    });

/*
| The stop-email pages (ops.md §1.3, §1.9; owner, 2026-11-01): the one thing of a switched-off store that
| still answers, because a link already sent must always stop the email. So they stay at their addresses
| but outside the group above, whose "store" middleware makes an off store a 404, and find the store
| themselves (StopEmailStore). The signature comes first: without a good one any code is the same 403,
| and nothing says whether the store exists or is off.
|
| GET is the page with its one button and changes nothing - mail scanners open links too (owner,
| 2026-10-30); POST, with the same signature, does it. The POST is also the mail client's one-click
| unsubscribe (RFC 8058), so it takes no CSRF token: the signature is its authority (OpsServiceProvider).
*/
Route::prefix('{store}/{locale}')
    ->middleware([StorefrontArea::SESSION, 'web', 'signed:relative', StopEmailStore::class])
    ->group(function (): void {
        Route::get('newsletter/leave/{subscriber}', [NewsletterController::class, 'leavePage'])->whereUlid('subscriber')->name('storefront.ops.newsletter.leave');
        Route::post('newsletter/leave/{subscriber}', [NewsletterController::class, 'leave'])->whereUlid('subscriber')->name('storefront.ops.newsletter.leave.post');
        Route::get('unsubscribe/{customer}', [NewsletterController::class, 'unsubscribePage'])->whereUlid('customer')->name('storefront.ops.unsubscribe');
        Route::post('unsubscribe/{customer}', [NewsletterController::class, 'unsubscribe'])->whereUlid('customer')->name('storefront.ops.unsubscribe.post');
    });
