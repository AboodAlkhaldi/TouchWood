<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Illuminate\Support\Facades\Route;
use Modules\Content\Presentation\Http\Controller\ThemePreviewController;

/*
| Content's addresses under a store in the shop (content.md §4.5). PR 10 adds the pages; for now **Stop Preview** on the
| preview's line (W5b): it only forgets this browser's preview cookie, so it needs no one signed in. Unnamed, as the
| layout writes it out: it stays out of every shop page's route list (Ziggy's storefront group).
*/

Route::prefix('{store}/{locale}')
    ->middleware(StorefrontArea::MIDDLEWARE)
    ->post('theme-preview/stop', [ThemePreviewController::class, 'stopOnShop']);
