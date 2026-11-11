<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;

use function Pest\Laravel\seed;

/*
| W8 · Blog in a real browser (content.md §4.4, §1.12): a category added in its dialog and found under the Categories
| tab, its Delete out of reach once it holds a post; a post written under it, saved as a draft and found under the
| Posts tab filtered by that category; Arabic, right to left, on a phone.
|
| Deliberately no RefreshDatabase: every name carries the run's own number (lesson 174), Arabic ones included.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('adds a category in its dialog, files a post under it, and keeps the category from being deleted', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::BLOG_MANAGE]);

    $page->navigate('/admin/content/blog/categories?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"add-category\"]') !== null"))->toBeTrue();
    $page->click('[data-test="add-category"]');
    expect(browserSoon($page, "document.querySelector('#name_ar') !== null"))->toBeTrue();
    $page->type('#name_ar', "نصائح {$run}")->type('#name_en', "Tips {$run}")->click('[data-test="save-category"]');
    expect(browserSoon($page, "document.body.textContent.includes('Category saved') && document.body.textContent.includes('Tips {$run}')"))->toBeTrue();
    $category = (string) DB::table('content.blog_categories')->where('name_en', "Tips {$run}")->value('id');

    $page->navigate('/admin/content/blog/posts/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"document-form\"]') !== null"))->toBeTrue();
    $page->type('#title_ar', "تدوينة {$run}")->type('#title_en', "Post {$run}")->type('#body_ar', 'نص')->select('#category', $category);
    $page->click('[data-test="save-draft"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/blog' && document.body.textContent.includes('Post saved')"))->toBeTrue();

    $page->select('#category-filter', $category);
    $post = (string) DB::table('content.blog_post_translations')->where('title', "Post {$run}")->value('post_id');
    expect(browserSoon($page, "window.location.search.includes('category={$category}') && document.querySelector('[data-test=\"document-{$post}\"]') !== null"))->toBeTrue();

    $page->click('[data-test="tab-categories"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/blog/categories'"))->toBeTrue();
    $at = (int) $page->script("[...document.querySelectorAll('[data-test^=\"category-more-\"]')].findIndex((button) => button.getAttribute('aria-label').includes('Tips {$run}'))");
    $page->click("[data-test=\"category-more-{$at}\"]");
    expect(browserSoon($page, "document.querySelector('[data-test=\"category-delete\"]')?.getAttribute('aria-disabled') === 'true' && document.body.textContent.includes('Move its posts first.')"))->toBeTrue()
        ->and(Fx::storeId('ae'))->not->toBe('');
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::BLOG_MANAGE], 'ar');
    $page->resize(375, 812);

    $page->navigate('/admin/content/blog/posts/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"document-form\"]') !== null"))->toBeTrue();
    $page->type('#title_ar', "تدوينة طويلة العنوان لا تنكسر على الهاتف {$run}")->type('#body_ar', 'نص');
    expect(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->click('[data-test="save-draft"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/blog' && document.querySelector('[data-test^=\"document-card-\"]') !== null"))->toBeTrue()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->navigate('/admin/content/blog/categories?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"tab-categories\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});
