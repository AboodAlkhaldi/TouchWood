<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;

use function Pest\Laravel\seed;

/*
| W9 · Projects in a real browser (content.md §4.4, §1.13): the list's note while none is published; a project with its
| city saved as a draft, then refused at Publish without a photo, in the form's own words at its top; Arabic, right to
| left, on a phone. Photos go up over HTTP in the feature test (the browser test's server takes no multipart, lesson 174).
|
| Deliberately no RefreshDatabase: every title carries the run's own number (lesson 174).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('saves a project as a draft and refuses its Publish without a photo', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::PROJECT_MANAGE]);

    $page->navigate('/admin/content/projects/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"document-form\"]') !== null && document.querySelector('[data-test=\"photos\"]') !== null"))->toBeTrue();
    $page->type('#title_ar', "مطبخ {$run}")->type('#title_en', "Kitchen {$run}")->type('#body_ar', 'قصة')->type('#body_en', 'A story')->type('#city_ar', 'الرياض')->type('#city_en', 'Riyadh');
    $page->click('[data-test="save-draft"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/projects' && document.body.textContent.includes('Project saved')"))->toBeTrue();

    $id = (string) DB::table('content.project_translations')->where('title', "Kitchen {$run}")->value('project_id');
    $page->navigate("/admin/content/projects/{$id}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"publish\"]') !== null && document.querySelector('#city_en').value === 'Riyadh'"))->toBeTrue();
    $page->click('[data-test="publish"]');
    expect(browserSoon($page, "document.querySelector('[role=\"alert\"]')?.textContent.length > 0"))->toBeTrue()
        ->and(DB::table('content.projects')->where('id', $id)->value('status'))->toBe('DRAFT');
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone, with the hidden page\'s note', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::PROJECT_MANAGE], 'ar');
    $page->resize(375, 812);
    DB::table('content.projects')->where('store_id', DB::table('platform.stores')->where('code', 'ae')->value('id'))->where('status', 'PUBLISHED')->update(['status' => 'DRAFT', 'published_at' => null]);

    $page->navigate('/admin/content/projects?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"projects-hidden\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->navigate('/admin/content/projects/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"document-form\"]') !== null"))->toBeTrue();
    $page->type('#title_ar', "مشروع طويل العنوان لا ينكسر على الهاتف {$run}")->type('#body_ar', 'قصة');
    expect(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page))
        ->and(Cb::latinDigits($page))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
