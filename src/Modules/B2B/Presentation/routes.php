<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\B2B\Presentation\Http\Controller\MyCompanyController;

/*
| The company's own page (b2b.md §4.5, amendment 14; frontend.md F11), inside the customer's
| account and under the store and language, as every account page is. Signed-in customers only; a
| visitor is sent to the store home, as Access's account pages send one.
|
| No route carries an account or an application: every handler reads who is asking from the session
| and acts on the account's one open application (§3.1). The ids that do appear — a document type,
| a request, a file — name something of that application, and the handler checks it is.
|
| Named storefront.* so the shop's pages carry them and the panel's never do (frontend.md §1.4).
*/

Route::prefix('{store}/{locale}')
    ->middleware([...StorefrontArea::MIDDLEWARE, StorefrontArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('account/company', [MyCompanyController::class, 'show'])->name('storefront.company');

        Route::post('account/company/draft/start', [MyCompanyController::class, 'start'])->name('storefront.company.start');
        Route::post('account/company/draft', [MyCompanyController::class, 'save'])->name('storefront.company.save');
        Route::post('account/company/draft/documents/{type}', [MyCompanyController::class, 'attach'])->name('storefront.company.attach');
        Route::post('account/company/draft/documents/{type}/remove', [MyCompanyController::class, 'detach'])->name('storefront.company.detach');
        Route::post('account/company/draft/answers/{answered}', [MyCompanyController::class, 'answer'])->name('storefront.company.answer');
        Route::post('account/company/draft/answers/{answered}/remove', [MyCompanyController::class, 'unanswer'])->name('storefront.company.unanswer');
        Route::post('account/company/draft/discard', [MyCompanyController::class, 'discard'])->name('storefront.company.discard');
        Route::post('account/company/draft/send', [MyCompanyController::class, 'send'])->name('storefront.company.send');
        Route::post('account/company/address', [MyCompanyController::class, 'address'])->name('storefront.company.address');
        Route::get('account/company/files/{file}', [MyCompanyController::class, 'file'])->name('storefront.company.file');
    });
