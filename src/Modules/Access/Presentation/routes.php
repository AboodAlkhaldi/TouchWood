<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Access\Presentation\Http\Controller\AdminPanelController;
use Modules\Access\Presentation\Http\Controller\CustomerAccountController;
use Modules\Access\Presentation\Http\Controller\CustomerSessionController;
use Modules\Access\Presentation\Http\Controller\StaffAccountController;
use Modules\Access\Presentation\Http\Controller\StaffAuthPageController;
use Modules\Access\Presentation\Http\Controller\StaffLinkController;
use Modules\Access\Presentation\Http\Controller\StaffSignInController;
use Modules\Access\Presentation\Http\Middleware\IdentifyCustomer;
use Modules\Access\Presentation\Http\Middleware\IdentifyStaff;
use Modules\Access\Presentation\Http\Middleware\RequireCustomer;
use Modules\Access\Presentation\Http\Middleware\RequireStaff;
use Modules\Access\Presentation\Http\Middleware\ShareAdminPage;
use Modules\Access\Presentation\Http\Middleware\UseAdminSession;
use Modules\Access\Presentation\Http\Middleware\UseStorefrontSession;

/*
| The admin panel's sign-in flows (spec §1.8, amendment 12): posts answered with redirects. The pages
| they come from are built in the frontend foundation stage. /admin is reserved by Platform, so no
| store is ever mistaken for it. The admin session cookie is set before "web" starts the session.
*/

Route::prefix('admin')
    ->middleware([UseAdminSession::ALIAS, 'web', IdentifyStaff::ALIAS, ShareAdminPage::ALIAS])
    ->group(function (): void {
        /*
        | The pages themselves (stage 2b step 1, frontend.md 3.1 A1-A9). Each is a plain GET that
        | changes nothing: opening a link from an email never acts, it only shows the form that
        | does. The posts below them are Access step 3b's, unchanged.
        */
        Route::get('sign-in', [StaffAuthPageController::class, 'signIn'])->name('access.staff.sign-in.page');
        Route::get('sign-in/phone', [StaffAuthPageController::class, 'phone'])->name('access.staff.sign-in.phone.page');
        Route::get('sign-in/code', [StaffAuthPageController::class, 'code'])->name('access.staff.sign-in.code.page');
        Route::get('password/forgot', [StaffAuthPageController::class, 'forgotPassword'])->name('access.staff.password.forgot.page');
        Route::get('password/reset/{token}', [StaffAuthPageController::class, 'resetPassword'])->name('access.staff.password.reset.page');
        Route::get('invitation/{token}', [StaffAuthPageController::class, 'acceptInvitation'])->name('access.staff.invitation.page');
        Route::get('invitation/{token}/code', [StaffAuthPageController::class, 'invitationCode'])->name('access.staff.invitation.code.page');
        Route::get('email-change/{token}', [StaffAuthPageController::class, 'confirmEmailChange'])->name('access.staff.email-change.page');

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
            Route::get('/', [AdminPanelController::class, 'home'])->name('access.staff.home');
            Route::post('current-store', [AdminPanelController::class, 'chooseStore'])->name('access.staff.current-store');

            Route::post('sign-out', [StaffAccountController::class, 'signOut'])->name('access.staff.sign-out');
            Route::post('account/password', [StaffAccountController::class, 'changePassword'])->name('access.staff.password.change');
        });

        // The theme and the displayed language, chosen before anybody signs in as well as after:
        // somebody who cannot read the interface has to be able to change it on the sign-in page.
        Route::post('preferences', [AdminPanelController::class, 'preferences'])->name('access.staff.preferences');
    });

/*
| The storefront flows a customer reaches from an email (spec §1.2): the verification link proves
| itself, so it needs no session — "signed" checks its signature and its expiry, and the store and
| language come from the URL, as every storefront page does.
*/

Route::prefix('{store}/{locale}')
    ->middleware([UseStorefrontSession::ALIAS, 'web', 'store', 'signed'])
    ->group(function (): void {
        Route::get('account/verify-email/{customer}', [CustomerAccountController::class, 'verifyEmail'])
            ->name('storefront.account.verify-email');
    });

/*
| Registering and signing in on the storefront (spec §1.2, §1.8). The session is the site's own, so
| the admin panel's is never touched; IdentifyCustomer names whoever the session holds.
*/

Route::prefix('{store}/{locale}')
    ->middleware([UseStorefrontSession::ALIAS, 'web', 'store', IdentifyCustomer::ALIAS])
    ->group(function (): void {
        Route::post('account/register', [CustomerSessionController::class, 'register'])->name('storefront.account.register');
        Route::post('account/sign-in', [CustomerSessionController::class, 'signIn'])->name('storefront.account.sign-in');
        Route::post('account/password/forgot', [CustomerSessionController::class, 'forgotPassword'])->name('storefront.account.password.forgot');
        Route::post('account/password/reset/{token}', [CustomerSessionController::class, 'resetPassword'])->name('storefront.account.reset-password');

        Route::middleware(RequireCustomer::ALIAS)->group(function (): void {
            Route::post('account/sign-out', [CustomerSessionController::class, 'signOut'])->name('storefront.account.sign-out');
            Route::post('account/password', [CustomerSessionController::class, 'changePassword'])->name('storefront.account.password.change');
            Route::post('account/verify-email/resend', [CustomerSessionController::class, 'resendVerification'])->name('storefront.account.verify-email.resend');
        });
    });
