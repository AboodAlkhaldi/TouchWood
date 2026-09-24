<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Access\Presentation\Http\Controller\AddressFormatController;
use Modules\Access\Presentation\Http\Controller\AdminPanelController;
use Modules\Access\Presentation\Http\Controller\CustomerAccountController;
use Modules\Access\Presentation\Http\Controller\CustomerAuthPageController;
use Modules\Access\Presentation\Http\Controller\CustomerOwnAccountController;
use Modules\Access\Presentation\Http\Controller\CustomersController;
use Modules\Access\Presentation\Http\Controller\CustomerSessionController;
use Modules\Access\Presentation\Http\Controller\RolesController;
use Modules\Access\Presentation\Http\Controller\StaffAccountController;
use Modules\Access\Presentation\Http\Controller\StaffAuthPageController;
use Modules\Access\Presentation\Http\Controller\StaffController;
use Modules\Access\Presentation\Http\Controller\StaffLinkController;
use Modules\Access\Presentation\Http\Controller\StaffOwnAccountController;
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

            /*
            | Roles (stage 2b step 2, frontend.md 3.4). Who may open any of these is Access's
            | answer, not the routing's: every handler behind them asks, and refuses in its own
            | words. A person without the permission gets the refusal, not a missing page.
            */
            /*
            | Staff (stage 2b step 2, frontend.md 3.3). Every screen and every button behind them
            | is offered only to somebody Access says may use it, and each handler asks again with
            | the stores in hand. A Super Admin is made and unmade by console command alone, so no
            | screen here offers either.
            */
            Route::get('staff', [StaffController::class, 'index'])->name('access.staff.list');
            // Before staff/{staff}, or "invite" is read as somebody's id.
            Route::get('staff/invite', [StaffController::class, 'invite'])->name('access.staff.invite.page');
            Route::post('staff/invite', [StaffController::class, 'sendInvitation'])->name('access.staff.invite');
            Route::get('staff/{staff}', [StaffController::class, 'show'])->name('access.staff.show');
            Route::get('staff/{staff}/role', [StaffController::class, 'role'])->name('access.staff.role.page');
            Route::post('staff/{staff}/role', [StaffController::class, 'changeRole'])->name('access.staff.role');
            Route::post('staff/{staff}/profile', [StaffController::class, 'updateProfile'])->name('access.staff.profile');
            Route::post('staff/{staff}/email', [StaffController::class, 'changeEmail'])->name('access.staff.email');
            Route::post('staff/{staff}/disable', [StaffController::class, 'disable'])->name('access.staff.disable');
            Route::post('staff/{staff}/enable', [StaffController::class, 'enable'])->name('access.staff.enable');
            Route::post('staff/{staff}/invitation/resend', [StaffController::class, 'resendInvitation'])->name('access.staff.invitation.resend');
            Route::post('staff/{staff}/invitation/cancel', [StaffController::class, 'cancelInvitation'])->name('access.staff.invitation.cancel');
            Route::post('staff/{staff}/refresh', [StaffController::class, 'refresh'])->name('access.staff.refresh');

            /*
            | Customers (stage 2b step 4, frontend.md 3.7). Staff see the customers of their own
            | stores; a Super Admin sees everyone. Nothing here edits a customer: their profile is
            | their own, and what staff may do is block, unblock, and start or stop the same
            | fourteen-day deletion the customer can start themselves - each with a reason, and
            | each admin-only.
            */
            Route::get('customers', [CustomersController::class, 'index'])->name('access.staff.customers');
            Route::get('customers/{customer}', [CustomersController::class, 'show'])->name('access.staff.customers.show');
            Route::post('customers/{customer}/block', [CustomersController::class, 'block'])->name('access.staff.customers.block');
            Route::post('customers/{customer}/unblock', [CustomersController::class, 'unblock'])->name('access.staff.customers.unblock');
            Route::post('customers/{customer}/delete', [CustomersController::class, 'requestDeletion'])->name('access.staff.customers.delete');
            Route::post('customers/{customer}/delete/cancel', [CustomersController::class, 'cancelDeletion'])->name('access.staff.customers.delete.cancel');

            /*
            | The store address format editor (stage 2b, frontend.md 3.7, decided 2026-09-19). A
            | country's address form is data: a store that asks for a district today and a postal
            | code tomorrow is a row changed here, not a deploy.
            */
            Route::get('address-formats', [AddressFormatController::class, 'index'])->name('access.staff.address-formats');
            // Not "{store}": Platform sets a global pattern for that name - the shop's two-to-
            // eight-letter country code - and a store's id would never match it, so the route
            // answered 404 and nothing said why (found by running it, 2026-09-25).
            Route::post('address-formats/{storeId}', [AddressFormatController::class, 'save'])->name('access.staff.address-formats.save');

            Route::get('roles', [RolesController::class, 'index'])->name('access.staff.roles');
            Route::get('roles/new', [RolesController::class, 'create'])->name('access.staff.roles.new');
            Route::post('roles', [RolesController::class, 'store'])->name('access.staff.roles.store');
            Route::get('roles/{role}', [RolesController::class, 'show'])->name('access.staff.roles.show');
            Route::get('roles/{role}/edit', [RolesController::class, 'edit'])->name('access.staff.roles.edit');
            Route::post('roles/{role}', [RolesController::class, 'update'])->name('access.staff.roles.update');
            Route::post('roles/{role}/clone', [RolesController::class, 'clone'])->name('access.staff.roles.clone');
            Route::post('roles/{role}/delete', [RolesController::class, 'destroy'])->name('access.staff.roles.delete');
            Route::post('roles/{role}/refresh', [RolesController::class, 'refresh'])->name('access.staff.roles.refresh');

            /*
            | "Account & settings" — a staff member's own account (stage 2b step 2, frontend.md
            | §3.2, B1-B4). One page with three tabs, and one POST per thing it can change. No
            | route here carries an id: each handler reads who is asking from the session, so
            | there is nothing to spell somebody else's account with. Changing the password is
            | the endpoint just above, which already exists and already says the right thing.
            */
            Route::get('account', [StaffOwnAccountController::class, 'show'])->name('access.staff.account.page');
            Route::post('account/profile', [StaffOwnAccountController::class, 'updateProfile'])->name('access.staff.account.profile');
            Route::post('account/email', [StaffOwnAccountController::class, 'changeEmail'])->name('access.staff.account.email');
            Route::post('account/phone', [StaffOwnAccountController::class, 'requestPhoneChange'])->name('access.staff.account.phone');
            Route::post('account/phone/code', [StaffOwnAccountController::class, 'confirmPhoneChange'])->name('access.staff.account.phone.confirm');
            Route::post('account/notifications', [StaffOwnAccountController::class, 'updateNotifications'])->name('access.staff.account.notifications');
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
    ->middleware(StorefrontArea::MIDDLEWARE)
    ->group(function (): void {
        /*
        | The pages themselves (stage 2b step 4, frontend.md 3.6, F3-F6), at the addresses the spec
        | names: /{store}/{lang}/register, not under "account/". The posts below keep the addresses
        | they were published at - nothing already in the world is renamed to match a page.
        */
        Route::get('register', [CustomerAuthPageController::class, 'register'])->name('storefront.register');
        Route::get('sign-in', [CustomerAuthPageController::class, 'signIn'])->name('storefront.sign-in');
        Route::get('verify-email', [CustomerAuthPageController::class, 'verifyEmail'])->name('storefront.verify-email');
        Route::get('password/forgot', [CustomerAuthPageController::class, 'forgotPassword'])->name('storefront.password.forgot');
        Route::get('password/reset/{token}', [CustomerAuthPageController::class, 'resetPassword'])->name('storefront.password.reset');

        Route::post('account/register', [CustomerSessionController::class, 'register'])->name('storefront.account.register');
        Route::post('account/sign-in', [CustomerSessionController::class, 'signIn'])->name('storefront.account.sign-in');
        Route::post('account/password/forgot', [CustomerSessionController::class, 'forgotPassword'])->name('storefront.account.password.forgot');
        Route::post('account/password/reset/{token}', [CustomerSessionController::class, 'resetPassword'])->name('storefront.account.reset-password');

        Route::middleware(RequireCustomer::ALIAS)->group(function (): void {
            /*
            | The customer's own account (stage 2b step 4, frontend.md 3.6, F7 and F8). One page
            | with tabs and one POST per thing it changes, and no route carrying an id: each
            | handler reads who is asking from the session, so there is nothing to spell somebody
            | else's account with.
            */
            Route::get('account', [CustomerOwnAccountController::class, 'show'])->name('storefront.account');
            Route::post('account/profile', [CustomerOwnAccountController::class, 'updateProfile'])->name('storefront.account.profile');
            Route::post('account/phone', [CustomerOwnAccountController::class, 'requestPhoneCode'])->name('storefront.account.phone');
            Route::post('account/phone/code', [CustomerOwnAccountController::class, 'confirmPhone'])->name('storefront.account.phone.confirm');

            // F9 and F10. A delete and a close are posts like any other change; the address id is
            // in the path because it names one of the customer's own rows, and the handler checks
            // it belongs to whoever is asking.
            Route::post('account/addresses', [CustomerOwnAccountController::class, 'saveAddress'])->name('storefront.account.addresses.save');
            Route::post('account/addresses/{address}/default', [CustomerOwnAccountController::class, 'setDefaultAddress'])->name('storefront.account.addresses.default');
            Route::post('account/addresses/{address}/delete', [CustomerOwnAccountController::class, 'deleteAddress'])->name('storefront.account.addresses.delete');
            Route::post('account/close', [CustomerOwnAccountController::class, 'close'])->name('storefront.account.close');

            Route::post('account/sign-out', [CustomerSessionController::class, 'signOut'])->name('storefront.account.sign-out');
            Route::post('account/password', [CustomerSessionController::class, 'changePassword'])->name('storefront.account.password.change');
            Route::post('account/verify-email/resend', [CustomerSessionController::class, 'resendVerification'])->name('storefront.account.verify-email.resend');
        });
    });
