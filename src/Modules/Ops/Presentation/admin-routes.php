<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Ops\Presentation\Http\Controller\BellController;
use Modules\Ops\Presentation\Http\Controller\ChoicesController;
use Modules\Ops\Presentation\Http\Controller\DailyReportController;
use Modules\Ops\Presentation\Http\Controller\NoticesController;
use Modules\Ops\Presentation\Http\Controller\ReportsController;
use Modules\Ops\Presentation\Http\Controller\SmsCountriesController;
use Modules\Ops\Presentation\Http\Controller\StaffMessagesController;
use Modules\Ops\Presentation\Http\Controller\SubscribersController;

/*
| The panel's bell (ops.md §1.2, §4): a signed-in staff member's own entries, over the stores they may
| view. And step 7's (§1.7-§1.9): offer notices, the message composer, the newsletter's subscribers -
| JSON, for the pages the screens agent builds. Who may do each is Ops' answer, never the routing's.
| The daily reports (§1.11): make, list, download, and the reader's own emails of them. The SMS countries
| (§3), and what each form may offer the reader.
|
| Mounted into the area App\Http\AdminArea names, as the other modules' screens are. Named ops.admin.*,
| which the panel's route list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('notifications', [BellController::class, 'index'])->name('ops.admin.bell');
        Route::get('notifications/unread', [BellController::class, 'unread'])->name('ops.admin.bell.unread');
        // Every entry, named before one entry's own route takes the word.
        Route::post('notifications/read-all', [BellController::class, 'readAll'])->name('ops.admin.bell.read-all');
        Route::post('notifications/{entry}/read', [BellController::class, 'read'])->name('ops.admin.bell.read');

        Route::get('notices', [NoticesController::class, 'index'])->name('ops.admin.notices');
        Route::post('notices', [NoticesController::class, 'store'])->name('ops.admin.notices.store');
        Route::get('notices/{notice}', [NoticesController::class, 'show'])->name('ops.admin.notices.show');
        Route::post('notices/{notice}', [NoticesController::class, 'update'])->name('ops.admin.notices.update');
        Route::post('notices/{notice}/cancel', [NoticesController::class, 'cancel'])->name('ops.admin.notices.cancel');

        // Step 8's reports (§1.10): a report's preview as JSON, and its Excel file.
        Route::get('reports/{report}', [ReportsController::class, 'show'])->name('ops.admin.reports.show');
        Route::get('reports/{report}/download', [ReportsController::class, 'download'])->name('ops.admin.reports.download');

        Route::get('messages/customers', [StaffMessagesController::class, 'customers'])->name('ops.admin.messages.customers');
        Route::get('messages', [StaffMessagesController::class, 'index'])->name('ops.admin.messages');
        Route::post('messages', [StaffMessagesController::class, 'send'])->name('ops.admin.messages.send');

        Route::get('newsletter/subscribers', [SubscribersController::class, 'index'])->name('ops.admin.subscribers');
        Route::post('newsletter/subscribers/{subscriber}/remove', [SubscribersController::class, 'remove'])->name('ops.admin.subscribers.remove');

        Route::get('daily-reports', [DailyReportController::class, 'index'])->name('ops.admin.daily-reports');
        Route::post('daily-reports', [DailyReportController::class, 'store'])->name('ops.admin.daily-reports.make');
        // The emails, named before one report's own route takes the word.
        Route::get('daily-reports/emails', [DailyReportController::class, 'emails'])->name('ops.admin.daily-reports.emails');
        Route::post('daily-reports/emails', [DailyReportController::class, 'setEmail'])->name('ops.admin.daily-reports.emails.set');
        Route::get('daily-reports/{report}/pdf', [DailyReportController::class, 'pdf'])->name('ops.admin.daily-reports.pdf');

        // What each of Ops' forms may offer this reader (§4): the stores, and the report kinds.
        Route::get('ops/choices', ChoicesController::class)->name('ops.admin.choices');

        // The SMS countries (§3; access.md amendment 90), Super Admins alone.
        Route::get('sms-countries', [SmsCountriesController::class, 'index'])->name('ops.admin.sms-countries');
        Route::post('sms-countries', [SmsCountriesController::class, 'store'])->name('ops.admin.sms-countries.add');
        Route::post('sms-countries/{country}/provider', [SmsCountriesController::class, 'provider'])->name('ops.admin.sms-countries.provider');
        Route::post('sms-countries/{country}/calling-code', [SmsCountriesController::class, 'callingCode'])->name('ops.admin.sms-countries.calling-code');
        Route::post('sms-countries/{country}/remove', [SmsCountriesController::class, 'remove'])->name('ops.admin.sms-countries.remove');
    });
