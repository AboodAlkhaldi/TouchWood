<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Content\Presentation\Http\Controller\RobotsController;
use Modules\Content\Presentation\Http\Controller\SitemapController;

/*
| The files search engines read (content.md §1.14): the robots file and the sitemaps. **No middleware group**: no
| session is opened and no store entered - a crawler gets the file and nothing else. `/robots.txt` and `/sitemap.xml`
| hold a dot, so they never match `{store}` (platform.md L6); `{store}/sitemap.xml` doesn't meet `{locale}`'s pattern,
| so no shop route takes it first.
*/

// Unnamed: no page links to them, so they stay out of every shop page's route list (Ziggy's storefront group).
Route::get('robots.txt', RobotsController::class);
Route::get('sitemap.xml', [SitemapController::class, 'index']);
Route::get('{store}/sitemap.xml', [SitemapController::class, 'store']);
Route::get('{store}/sitemap-{part}.xml', [SitemapController::class, 'part'])->where('part', '[1-9][0-9]{0,3}');
