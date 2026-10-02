<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\B2B\Presentation\Http\Controller\StaffCompaniesController;
use Modules\B2B\Presentation\Http\Controller\StaffTypesController;

/*
| B2B's screens in the admin panel (step 7, b2b.md §4.6, amendment 19): the company list, one
| company with its decisions, and the store's two type lists.
|
| Mounted into the area App\Http\AdminArea names, as Platform's screens are: Access implements the
| door, and B2B puts its pages behind it without reaching into Access. Who may open any of these is
| B2B's answer, never the routing's - each read model and each handler asks for its own job in the
| right store (§3.2): a company's home store, a type's own store, or the store in the panel's header.
|
| Named b2b.admin.*, which the panel's route list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('companies', [StaffCompaniesController::class, 'index'])->name('b2b.admin.companies');
        Route::get('companies/{company}', [StaffCompaniesController::class, 'show'])->name('b2b.admin.companies.show');
        Route::post('companies/{company}/approve', [StaffCompaniesController::class, 'approve'])->name('b2b.admin.companies.approve');
        Route::post('companies/{company}/reject', [StaffCompaniesController::class, 'reject'])->name('b2b.admin.companies.reject');
        Route::post('companies/{company}/suspend', [StaffCompaniesController::class, 'suspend'])->name('b2b.admin.companies.suspend');
        Route::post('companies/{company}/reinstate', [StaffCompaniesController::class, 'reinstate'])->name('b2b.admin.companies.reinstate');
        Route::post('companies/{company}/type', [StaffCompaniesController::class, 'correctType'])->name('b2b.admin.companies.type');
        // A paper or a file answer, through a link that lasts 30 minutes; each opening is audited
        // (amendment 10(f)). A GET, as the company's own file is: a link, followed to the file.
        Route::get('companies/{company}/files/{file}', [StaffCompaniesController::class, 'file'])->name('b2b.admin.companies.file');

        // The store's two lists: always the store in the panel's header, never one in the request.
        Route::get('company-types', [StaffTypesController::class, 'companyTypes'])->name('b2b.admin.company-types');
        Route::post('company-types', [StaffTypesController::class, 'addCompanyType'])->name('b2b.admin.company-types.add');
        Route::post('company-types/{type}/rename', [StaffTypesController::class, 'renameCompanyType'])->name('b2b.admin.company-types.rename');
        Route::post('company-types/{type}/move', [StaffTypesController::class, 'moveCompanyType'])->name('b2b.admin.company-types.move');
        Route::post('company-types/{type}/deactivate', [StaffTypesController::class, 'deactivateCompanyType'])->name('b2b.admin.company-types.deactivate');
        Route::post('company-types/{type}/activate', [StaffTypesController::class, 'activateCompanyType'])->name('b2b.admin.company-types.activate');
        Route::post('company-types/{type}/transfer', [StaffTypesController::class, 'transferCompanyType'])->name('b2b.admin.company-types.transfer');

        Route::get('document-types', [StaffTypesController::class, 'documentTypes'])->name('b2b.admin.document-types');
        Route::post('document-types', [StaffTypesController::class, 'addDocumentType'])->name('b2b.admin.document-types.add');
        Route::post('document-types/{type}/rename', [StaffTypesController::class, 'renameDocumentType'])->name('b2b.admin.document-types.rename');
        Route::post('document-types/{type}/move', [StaffTypesController::class, 'moveDocumentType'])->name('b2b.admin.document-types.move');
        Route::post('document-types/{type}/require', [StaffTypesController::class, 'requireDocumentType'])->name('b2b.admin.document-types.require');
        Route::post('document-types/{type}/deactivate', [StaffTypesController::class, 'deactivateDocumentType'])->name('b2b.admin.document-types.deactivate');
        Route::post('document-types/{type}/activate', [StaffTypesController::class, 'activateDocumentType'])->name('b2b.admin.document-types.activate');

        Route::post('type-lists/reviewed', [StaffTypesController::class, 'markReviewed'])->name('b2b.admin.type-lists.reviewed');
    });
