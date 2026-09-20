<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Access\Presentation\Http\Controller\CustomerAccountController;
use Modules\Access\Presentation\Http\Controller\StaffAccountController;
use Modules\Access\Presentation\Http\Controller\StaffLinkController;
use Modules\Access\Presentation\Http\Controller\StaffSignInController;
use Modules\Access\Presentation\Http\Middleware\IdentifyStaff;
use Modules\Access\Presentation\Http\Middleware\RequireStaff;
use Modules\Access\Presentation\Http\Middleware\UseAdminSession;

/*
| The admin panel's sign-in flows (spec §1.8, amendment 12): posts answered with redirects. The pages
| they come from are built in the frontend foundation stage. /admin is reserved by Platform, so no
| store is ever mistaken for it. The admin session cookie is set before "web" starts the session.
*/

Route::prefix('admin')
    ->middleware([UseAdminSession::ALIAS, 'web', IdentifyStaff::ALIAS])
    ->group(function (): void {
        Route::post('sign-in', [StaffSignInController::class, 'password'])->name('access.staff.sign-in');
        Route::post('sign-in/phone', [StaffSignInController::class, 'phone'])->name('access.staff.sign-in.phone');
        Route::post('sign-in/code', [StaffSignInController::class, 'code'])->name('access.staff.sign-in.code');
        Route::post('sign-in/code/resend', [StaffSignInController::class, 'resend'])->name('access.staff.sign-in.resend');

        Route::post('password/forgot', [StaffAccountController::class, 'forgotPassword'])->name('access.staff.password.forgot');
        Route::post('password/reset/{token}', [StaffAccountController::class, 'resetPassword'])->name('access.staff.password.reset');

        Route::post('invitation/{token}', [StaffLinkController::class, 'acceptInvitation'])->name('access.staff.invitation.accept');
        Route::post('invitation/{token}/code', [StaffLinkController::class, 'confirmInvitation'])->name('access.staff.invitation.confirm');
        Route::post('email-change/{token}', [StaffLinkController::class, 'confirmEmailChange'])->name('access.staff.email-change.confirm');

        Route::middleware(RequireStaff::ALIAS)->group(function (): void {
            Route::post('sign-out', [StaffAccountController::class, 'signOut'])->name('access.staff.sign-out');
            Route::post('account/password', [StaffAccountController::class, 'changePassword'])->name('access.staff.password.change');
        });
    });

/*
| The storefront flows a customer reaches from an email (spec §1.2): the verification link proves
| itself, so it needs no session — "signed" checks its signature and its expiry, and the store and
| language come from the URL, as every storefront page does.
*/

Route::prefix('{store}/{locale}')
    ->middleware(['web', 'store', 'signed'])
    ->group(function (): void {
        Route::get('account/verify-email/{customer}', [CustomerAccountController::class, 'verifyEmail'])
            ->name('storefront.account.verify-email');
    });
