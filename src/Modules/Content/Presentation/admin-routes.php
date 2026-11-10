<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Content\Presentation\Http\Controller\TranslateController;

/*
| Content's routes in the admin panel (content.md §4.3): for now Translate, which every Content editor calls
| (§1.3); the screens' own pages join with their PRs.
|
| Mounted into the area App\Http\AdminArea names, as Sync's are. Who may do any of this is Content's answer,
| never the routing's: each handler asks for its permission in the store (§3).
|
| Named content.admin.*, which the panel's route list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::post('content/translate', TranslateController::class)->name('content.admin.translate');
    });
