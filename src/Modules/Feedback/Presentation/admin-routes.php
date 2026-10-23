<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Feedback\Presentation\Http\Controller\SupportController;

/*
| Feedback's screens in the admin panel (feedback.md §4.1): Support's Reviews and Product Questions, and
| Customers' Favourited Products.
|
| Mounted into the area App\Http\AdminArea names, as the other modules' screens are. Who may open any of
| these is Feedback's answer, never the routing's. Named feedback.admin.*, which the panel's route list
| carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('reviews', [SupportController::class, 'reviews'])->name('feedback.admin.reviews');
        Route::post('reviews/{review}/approve', [SupportController::class, 'approve'])->name('feedback.admin.reviews.approve');
        Route::post('reviews/{review}/reject', [SupportController::class, 'reject'])->name('feedback.admin.reviews.reject');
        Route::post('reviews/{review}/unpublish', [SupportController::class, 'unpublish'])->name('feedback.admin.reviews.unpublish');
        Route::get('questions', [SupportController::class, 'questions'])->name('feedback.admin.questions');
        Route::post('questions/{question}/answer', [SupportController::class, 'answer'])->name('feedback.admin.questions.answer');
        Route::post('questions/{question}/correct', [SupportController::class, 'correct'])->name('feedback.admin.questions.correct');
        Route::post('questions/{question}/reject', [SupportController::class, 'rejectQuestion'])->name('feedback.admin.questions.reject');
        Route::post('questions/{question}/unpublish', [SupportController::class, 'unpublishQuestion'])->name('feedback.admin.questions.unpublish');
        Route::get('favourites', [SupportController::class, 'favourites'])->name('feedback.admin.favourites');
    });
