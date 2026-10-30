<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Ops\Presentation\Http\Controller\BellController;
use Modules\Ops\Presentation\Http\Controller\DailyReportController;
use Modules\Ops\Presentation\Http\Controller\NoticesController;
use Modules\Ops\Presentation\Http\Controller\StaffMessagesController;
use Modules\Ops\Presentation\Http\Controller\SubscribersController;

/*
| The panel's bell (ops.md §1.2, §4): a signed-in staff member's own entries, over the stores they may
| view. And step 7's (§1.7-§1.9): offer notices, the message composer, the newsletter's subscribers -
| JSON, for the pages the screens agent builds. Who may do each is Ops' answer, never the routing's.
| The daily reports (§1.11): make, list, download, and the reader's own emails of them.
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

        Route::get('messages/customers', [StaffMessagesController::class, 'customers'])->name('ops.admin.messages.customers');
        Route::post('messages', [StaffMessagesController::class, 'send'])->name('ops.admin.messages.send');

        Route::get('newsletter/subscribers', [SubscribersController::class, 'index'])->name('ops.admin.subscribers');
        Route::post('newsletter/subscribers/{subscriber}/remove', [SubscribersController::class, 'remove'])->name('ops.admin.subscribers.remove');

        Route::get('daily-reports', [DailyReportController::class, 'index'])->name('ops.admin.daily-reports');
        Route::post('daily-reports', [DailyReportController::class, 'store'])->name('ops.admin.daily-reports.make');
        // The emails, named before one report's own route takes the word.
        Route::get('daily-reports/emails', [DailyReportController::class, 'emails'])->name('ops.admin.daily-reports.emails');
        Route::post('daily-reports/emails', [DailyReportController::class, 'setEmail'])->name('ops.admin.daily-reports.emails.set');
        Route::get('daily-reports/{report}/pdf', [DailyReportController::class, 'pdf'])->name('ops.admin.daily-reports.pdf');
    });
