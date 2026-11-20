<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicy;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicyHandler;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyInput;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| A policy's Unpublish… and Delete… per store in a real browser (the owner's decisions of 2026-11-20): a person who holds
| one of the policy's two stores turns that store off - the other shown disabled, with why - and lands on the store's own
| switched-off copy; a person who holds both picks one, both, or none (the button then out of reach, with why); a draft
| deleted in one store; Publish out of reach, with why, for a draft also serving a store not the person's; at 375 x 812.
|
| Deliberately no RefreshDatabase: every title and address carries the run's own number (lesson 174), and the policies
| are of the kind Other, which a store may publish several of, so earlier runs' never block this one's Publish.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * The run's policy of the kind Other for the UAE and Egypt, made as an editor of both; published unless told. Who acts is
 * put back after: the browser's requests run in this process, and would act as that editor too.
 */
function contentPolicyStoresPolicy(string $run, bool $publish = true): string
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;

    try {
        return contentPolicyStoresMake($run, $publish);
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

function contentPolicyStoresMake(string $run, bool $publish): string
{
    Cx::editor([ContentPermissions::POLICY_MANAGE], ['ae', 'eg']);
    $id = app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(...[
        'id' => null, 'kind' => 'OTHER', 'storeIds' => [Fx::storeId('ae'), Fx::storeId('eg')], 'version' => 0,
        'titleAr' => "سياسة الضمان {$run}", 'titleEn' => "Warranty terms {$run}", 'slugAr' => "الضمان-{$run}", 'slugEn' => "warranty-{$run}",
        'bodyAr' => 'سنتان على كل مفصلة.', 'bodyEn' => 'Two years on every hinge.',
    ])));

    if ($publish) {
        app(PublishPolicyHandler::class)->handle(new PublishPolicy($id, 1));
    }

    return $id;
}

it('lets a person holding one of its two stores turn that store off, the other disabled with why, landing on the store\'s own copy, on a phone', function () {
    $run = Cb::run();
    $policy = contentPolicyStoresPolicy($run);
    $page = Cb::signIn([ContentPermissions::POLICY_MANAGE], 'en', ['ae']);
    $page->resize(375, 812);

    $page->navigate("/admin/content/policies/{$policy}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"policy-more\"]') !== null"))->toBeTrue();
    $page->click('[data-test="policy-more"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"menu-unpublish\"]') !== null"))->toBeTrue();
    $page->click('[data-test="menu-unpublish"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-unpublish-stores\"]') !== null"))->toBeTrue()
        // His store ticked; Egypt shown, out of reach, with why.
        ->and($page->script("document.querySelector('[data-test=\"confirm-unpublish-store-ae\"]').getAttribute('data-state')"))->toBe('checked')
        ->and($page->script("document.querySelector('[data-test=\"confirm-unpublish-other\"]').disabled"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"confirm-unpublish-stores\"]').textContent"))->toContain('EgyptNot yours to change')
        ->and(browserSoon($page, "document.querySelector('[data-test=\"confirm-unpublish-summary\"]')?.textContent === 'United Arab Emirates will keep a copy of their own as a draft, to edit and publish again; Egypt will keep the published policy.'"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->click('[data-test="confirm-unpublish"]');
    expect(browserSoon($page, "document.body.textContent.includes('Unpublished in the stores ticked.')"))->toBeTrue();
    $copy = (string) DB::table('content.policy_stores')->join('content.policy_translations', 'policy_translations.policy_id', '=', 'policy_stores.policy_id')
        ->where('policy_translations.title', "Warranty terms {$run}")->where('policy_stores.store_id', Fx::storeId('ae'))->value('policy_stores.policy_id');

    expect($copy)->not->toBe($policy)
        ->and(browserSoon($page, "window.location.pathname === '/admin/content/policies/{$copy}'"))->toBeTrue()
        ->and(DB::table('content.policies')->where('id', $copy)->value('status'))->toBe('DRAFT')
        ->and(DB::table('content.policies')->where('id', $policy)->value('status'))->toBe('PUBLISHED')
        ->and(DB::table('content.policy_stores')->where('policy_id', $policy)->pluck('store_id')->all())->toBe([Fx::storeId('eg')])
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});

it('lets a person holding both pick one store, both or none - the button out of reach with none - and unpublish in both', function () {
    $run = Cb::run();
    $policy = contentPolicyStoresPolicy($run);
    $page = Cb::signIn([ContentPermissions::POLICY_MANAGE], 'en', ['ae', 'eg']);

    $page->navigate("/admin/content/policies/{$policy}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"policy-more\"]') !== null"))->toBeTrue();
    $page->click('[data-test="policy-more"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"menu-unpublish\"]') !== null"))->toBeTrue();
    $page->click('[data-test="menu-unpublish"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-unpublish-summary\"]')?.textContent === 'The policy is unpublished in every store it serves.'"))->toBeTrue()
        ->and($page->script("document.querySelectorAll('[data-test=\"confirm-unpublish-other\"]').length"))->toBe(0);

    $page->click('[data-test="confirm-unpublish-store-eg"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-unpublish-summary\"]')?.textContent === 'United Arab Emirates will keep a copy of their own as a draft, to edit and publish again; Egypt will keep the published policy.'"))->toBeTrue();
    $page->click('[data-test="confirm-unpublish-store-ae"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-unpublish\"]').getAttribute('aria-disabled') === 'true' && document.querySelector('[data-test=\"confirm-unpublish-summary\"]') === null"))->toBeTrue();
    // Pressed anyway: nothing happens.
    $page->script("document.querySelector('[data-test=\"confirm-unpublish\"]').click()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-unpublish\"]') !== null"))->toBeTrue()
        ->and(DB::table('content.policies')->where('id', $policy)->value('status'))->toBe('PUBLISHED');

    $page->click('[data-test="confirm-unpublish-store-ae"]')->click('[data-test="confirm-unpublish-store-eg"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-unpublish\"]').getAttribute('aria-disabled') === null"))->toBeTrue();
    $page->click('[data-test="confirm-unpublish"]');
    expect(browserSoon($page, "document.body.textContent.includes('Policy unpublished') && window.location.pathname === '/admin/content/policies/{$policy}'"))->toBeTrue()
        ->and(DB::table('content.policies')->where('id', $policy)->value('status'))->toBe('DRAFT')
        ->and(DB::table('content.policy_stores')->where('policy_id', $policy)->count())->toBe(2);
    $page->assertNoJavaScriptErrors();
});

it('deletes a draft in one of its stores, and shows Publish out of reach, with why, to a person who does not hold every store it serves', function () {
    $run = Cb::run();
    $draft = contentPolicyStoresPolicy($run, publish: false);

    $one = Cb::signIn([ContentPermissions::POLICY_MANAGE], 'en', ['ae']);
    $one->navigate("/admin/content/policies/{$draft}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($one, "document.querySelector('[data-test=\"publish\"]')?.getAttribute('aria-disabled') === 'true'"))->toBeTrue();
    $one->hover('[data-test="publish"]');
    expect(browserSoon($one, "[...document.querySelectorAll('[role=\"tooltip\"]')].some((tip) => tip.textContent.includes('It also serves Egypt, where you can\\'t publish it.'))"))->toBeTrue();

    $page = Cb::signIn([ContentPermissions::POLICY_MANAGE], 'en', ['ae', 'eg']);
    $page->navigate("/admin/content/policies/{$draft}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"publish\"]')?.getAttribute('aria-disabled') === null"))->toBeTrue();
    $page->click('[data-test="policy-more"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"menu-delete\"]') !== null"))->toBeTrue();
    $page->click('[data-test="menu-delete"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-delete-stores\"]') !== null && document.querySelector('[data-test=\"confirm-delete-summary\"]') === null"))->toBeTrue();
    $page->click('[data-test="confirm-delete-store-eg"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-delete-summary\"]')?.textContent === 'United Arab Emirates will be taken off this draft; Egypt will keep it.'"))->toBeTrue();
    $page->click('[data-test="confirm-delete"]');
    expect(browserSoon($page, "document.body.textContent.includes('Draft deleted in the stores ticked') && window.location.pathname === '/admin/content/policies/{$draft}'"))->toBeTrue()
        ->and(DB::table('content.policy_stores')->where('policy_id', $draft)->pluck('store_id')->all())->toBe([Fx::storeId('eg')]);
    $page->assertNoJavaScriptErrors();
    $one->assertNoJavaScriptErrors();
});
