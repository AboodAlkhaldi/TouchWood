<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicy;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicyHandler;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicy;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicyHandler;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Content › Policies in a real browser (flags-2026-11.md S3.7; the owner's answers of 2026-11-20): a policy made with
| its kind and two stores ticked, published; on its page, a store unticked with the words unchanged says that store
| will be left without it and asks once more at the save, and changed words say the stores ticked get their own copy -
| the save landing on that copy; Arabic, right to left, in Latin digits, on a phone.
|
| Deliberately no RefreshDatabase: every title carries the run's own number (lesson 174), and the policies are of the
| kind Other, which a store may publish several of, so earlier runs' never block this one's Publish.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('makes a policy for two stores, publishes it, and says before saving when a store is left without it or splits off', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::POLICY_MANAGE], 'en', ['ae', 'eg']);

    $page->navigate('/admin/content/policies/new', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"policy-form\"]') !== null"))->toBeTrue()
        // Two stores to choose from: none ticked yet, so neither save is in reach.
        ->and($page->script("document.querySelector('[data-test=\"store-ae\"]').getAttribute('data-state')"))->toBe('unchecked');
    $page->select('#kind', 'OTHER')
        ->click('[data-test="store-ae"]')
        ->click('[data-test="store-eg"]')
        ->type('#title_ar', "سياسة الضمان الممتد {$run}")
        ->type('#title_en', "Extended warranty {$run}")
        ->type('#body_ar', "# الضمان\n\nسنتان على كل مفصلة.")
        ->type('#body_en', "# Warranty\n\nTwo years on every hinge.");
    // The address is drawn under the first store ticked, in the stores' order: Egypt before the UAE.
    expect(browserSoon($page, "document.querySelector('[data-test=\"address-en\"]').textContent.includes('/eg/en/policies/')"))->toBeTrue();
    $page->click('[data-test="publish"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/policies' && document.body.textContent.includes('Policy published')"))->toBeTrue();
    $id = (string) DB::table('content.policy_translations')->where('title', "Extended warranty {$run}")->value('policy_id');
    expect(DB::table('content.policies')->where('id', $id)->value('status'))->toBe('PUBLISHED')
        ->and(browserSoon($page, "document.querySelector('[data-test=\"policy-{$id}\"] [data-test=\"stores\"]')?.textContent === 'Egypt, United Arab Emirates'"))->toBeTrue();

    $page->click("[data-test=\"policy-{$id}\"]");
    expect(browserSoon($page, "window.location.pathname === '/admin/content/policies/{$id}' && document.querySelector('[data-test=\"kind-fixed\"]') !== null"))->toBeTrue()
        // Once published, Delete stays in the menu out of reach, its reason under it - only Unpublish is offered here.
        ->and($page->script("document.querySelector('[data-test=\"text-version\"]').textContent"))->toBe('Version 1');

    // Egypt unticked, the words unchanged: Egypt is left without it, said now and asked again at the save.
    $page->click('[data-test="store-eg"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"removed-note\"]')?.textContent === 'Egypt will have no published Other.'"))->toBeTrue();
    $page->click('[data-test="publish"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-removed\"]') !== null"))->toBeTrue();
    $page->keys('[data-test="confirm-removed"]', 'Escape');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-removed\"]') === null"))->toBeTrue();

    // The words changed too: the UAE gets its own copy, Egypt keeps the current text.
    $page->type('#body_en', "# Warranty\n\nThree years on every hinge.");
    expect(browserSoon($page, "document.querySelector('[data-test=\"split-note\"]')?.textContent === 'United Arab Emirates will get its own copy; Egypt will keep the current text.' && document.querySelector('[data-test=\"removed-note\"]') === null"))->toBeTrue();
    $page->click('[data-test="publish"]');
    expect(browserSoon($page, "document.body.textContent.includes('Saved as a policy of its own for the stores ticked')"))->toBeTrue();
    $copy = (string) DB::table('content.policy_stores')->join('content.policy_translations', 'policy_translations.policy_id', '=', 'policy_stores.policy_id')
        ->where('policy_translations.title', "Extended warranty {$run}")->where('policy_stores.store_id', DB::table('platform.stores')->where('code', 'ae')->value('id'))->value('policy_stores.policy_id');
    expect($copy)->not->toBe($id)
        ->and(browserSoon($page, "window.location.pathname === '/admin/content/policies/{$copy}'"))->toBeTrue()
        ->and(DB::table('content.policy_stores')->where('policy_id', $id)->count())->toBe(1);
    $page->assertNoJavaScriptErrors();
});

it('shows a draft\'s stores not the person\'s read-only, and offers Delete only while never published, else out of reach with why', function () {
    $run = Cb::run();
    $both = Cb::signIn([ContentPermissions::POLICY_MANAGE], 'en', ['ae', 'eg']);
    $both->navigate('/admin/content/policies/new', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($both, "document.querySelector('[data-test=\"policy-form\"]') !== null"))->toBeTrue();
    $both->select('#kind', 'OTHER')->click('[data-test="store-ae"]')->click('[data-test="store-eg"]')
        ->type('#title_ar', "سياسة {$run}")->type('#title_en', "Draft policy {$run}")->type('#body_ar', 'نص السياسة')->type('#body_en', 'The policy text');
    $both->click('[data-test="save-draft"]');
    expect(browserSoon($both, "document.body.textContent.includes('Policy saved')"))->toBeTrue();
    $id = (string) DB::table('content.policy_translations')->where('title', "Draft policy {$run}")->value('policy_id');

    $page = Cb::signIn([ContentPermissions::POLICY_MANAGE], 'en', ['ae']);
    $page->navigate("/admin/content/policies/{$id}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"also-serves\"]')?.textContent === 'Also serves: Egypt (not yours to change)'"))->toBeTrue()
        ->and($page->script("document.querySelectorAll('[data-test=\"stores\"] button[role=\"checkbox\"]').length"))->toBe(1);
    $page->click('[data-test="policy-more"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"menu-delete\"]') !== null && document.querySelector('[data-test=\"delete-blocked\"]') === null"))->toBeTrue();

    // Published once and unpublished: never deleted, its versions kept - Delete is shown out of reach, with why.
    Cx::editor([ContentPermissions::POLICY_MANAGE], ['ae', 'eg']);
    app(PublishPolicyHandler::class)->handle(new PublishPolicy($id, (int) DB::table('content.policies')->where('id', $id)->value('version')));
    app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($id, (int) DB::table('content.policies')->where('id', $id)->value('version')));
    $both->navigate("/admin/content/policies/{$id}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($both, "document.querySelector('[data-test=\"policy-more\"]') !== null"))->toBeTrue();
    $both->click('[data-test="policy-more"]');
    expect(browserSoon($both, "document.querySelector('[data-test=\"menu-delete\"]')?.getAttribute('aria-disabled') === 'true' && document.querySelector('[data-test=\"delete-blocked\"]')?.textContent === 'Published before, so its versions are kept. Unpublish it instead.'"))->toBeTrue();
    // Pressed anyway (the browser's own click, as a mouse would: the item is out of reach, so the driver won't): nothing asked.
    $both->script("document.querySelector('[data-test=\"menu-delete\"]').click()");
    expect(browserSoon($both, "document.querySelector('[data-test=\"confirm-delete\"]') === null"))->toBeTrue()
        ->and(DB::table('content.policies')->where('id', $id)->exists())->toBeTrue();
    $both->assertNoJavaScriptErrors();
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::POLICY_MANAGE], 'ar', ['ae', 'eg']);
    $page->resize(375, 812);

    $page->navigate('/admin/content/policies/new', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"policy-form\"]') !== null"))->toBeTrue();
    $page->select('#kind', 'OTHER')->click('[data-test="store-ae"]')
        ->type('#title_ar', "سياسة طويلة العنوان جدا لا تنكسر على الهاتف {$run}")->type('#body_ar', "| المدة | الضمان |\n| 24 | شهرًا |");
    expect(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->click('[data-test="save-draft"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/policies'"))->toBeTrue();
    expect(browserSoon($page, "document.querySelector('[data-test^=\"policy-card-\"]') !== null"))->toBeTrue()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $id = (string) DB::table('content.policy_translations')->where('title', "سياسة طويلة العنوان جدا لا تنكسر على الهاتف {$run}")->value('policy_id');

    // The policy's own page, ticked for the UAE only: Egypt is the other store on offer.
    $page->navigate("/admin/content/policies/{$id}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"kind-fixed\"]') !== null"))->toBeTrue()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});
