<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicy;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicyHandler;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicy;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicyHandler;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyInput;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentBrowser as Cb;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| A policy's page and the footer's row of policy links in a real browser (flags-2026-11.md S3.1, S3.2, S3.5): "Last
| updated" under the title, a kept version with its line to the current one, the row on the footer's bottom line - each
| at 375 x 812 in both languages, nothing sideways and every digit 0-9.
|
| Deliberately no RefreshDatabase (the browser suite's way): the policy is the run's own, of the kind Other, its address
| made unique by the run's number (lesson 174); the earlier runs' policies in `eg` are unpublished first, so the
| footer's row holds this run's alone.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * The run's policy in `eg`, published, then its English changed: version 2 now, version 1 kept.
 *
 * @return array{slugEn: string, slugAr: string}
 */
function contentShopPolicy(string $run): array
{
    $eg = Fx::storeId('eg');
    DB::table('content.policies')->where('status', 'PUBLISHED')->whereIn('id', DB::table('content.policy_stores')->select('policy_id')->where('store_id', $eg))
        ->update(['status' => 'DRAFT', 'published_at' => null]);
    Cx::editor([ContentPermissions::POLICY_MANAGE], ['eg']);
    $words = ['kind' => 'OTHER', 'storeIds' => [$eg], 'titleAr' => "سياسة الضمان {$run}", 'titleEn' => "Warranty terms {$run}", 'slugAr' => "الضمان-{$run}", 'slugEn' => "warranty-{$run}", 'bodyAr' => "# الضمان\n\nسنتان على كل مفصلة."];
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 08:00:00+00'));
    $id = app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(...[...$words, 'id' => null, 'version' => 0, 'bodyEn' => "# Warranty\n\nTwo years on every hinge."])));
    app(PublishPolicyHandler::class)->handle(new PublishPolicy($id, 1));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 08:00:00+00'));
    app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(...[...$words, 'id' => $id, 'version' => 2, 'bodyEn' => "# Warranty\n\nThree years on every hinge."])));
    CarbonImmutable::setTestNow();
    app(ContentCache::class)->invalidate();

    return ['slugEn' => "warranty-{$run}", 'slugAr' => "الضمان-{$run}"];
}

it('draws a policy with "Last updated", a kept version with its line to the current one, and the footer\'s row, on a phone in both languages', function () {
    $run = Cb::run();
    $slugs = contentShopPolicy($run);
    $page = visit("/eg/en/policies/{$slugs['slugEn']}");
    $page->resize(375, 812);

    foreach (['en' => $slugs['slugEn'], 'ar' => rawurlencode($slugs['slugAr'])] as $locale => $slug) {
        foreach (["/eg/{$locale}/policies/{$slug}", "/eg/{$locale}/policies/{$slug}?version=1"] as $address) {
            $page->navigate($address, BROWSER_PAGE_LOAD);

            // The current text says when it was updated; version 1 says it is old instead (owner, 2026-11-20).
            $mark = str_contains($address, 'version=') ? 'policy-past' : 'policy-updated';
            expect(browserSoon($page, "document.querySelector('[data-test=\"content-policy\"]') !== null && document.querySelector('[data-test=\"{$mark}\"]') !== null"))->toBeTrue("{$address}: not drawn")
                ->and($page->script('document.documentElement.lang'))->toBe($locale)
                ->and(browserSoon($page, "[...document.querySelectorAll('[data-test=\"footer-policies\"] a')].some((link) => link.textContent.includes('{$run}'))"))->toBeTrue("{$address}: no policy link in the footer")
                ->and(Cb::fitsPhone($page))->toBeTrue("{$address}: ".Cb::overflow($page))
                ->and(Cb::latinDigits($page))->toBeTrue("{$address}: a digit not 0-9");
        }
    }

    // English: the current text updated on the 18th; version 1 says so and links back.
    $page->navigate("/eg/en/policies/{$slugs['slugEn']}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"policy-updated\"]')?.textContent === 'Last updated November 18, 2026' && document.querySelector('[data-test=\"policy-past\"]') === null && document.body.textContent.includes('Three years')"))->toBeTrue();
    $page->navigate("/eg/en/policies/{$slugs['slugEn']}?version=1", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"policy-old\"]')?.textContent === 'This is an old version of the Warranty terms {$run}. It is no longer valid.' && document.querySelector('[data-test=\"policy-in-force\"]')?.textContent === 'In force from November 1, 2026 to November 18, 2026.' && document.querySelector('[data-test=\"policy-updated\"]') === null && document.body.textContent.includes('Two years')"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"policy-current\"]').textContent"))->toBe("Read the current Warranty terms {$run}")
        ->and($page->script("document.querySelector('meta[name=\"robots\"]')?.getAttribute('content') ?? ''"))->toContain('noindex');
    $page->click('[data-test="policy-current"]');
    expect(browserSoon($page, "window.location.search === '' && document.body.textContent.includes('Three years')"))->toBeTrue();

    // The footer's link opens the policy.
    $page->navigate('/eg/en', BROWSER_PAGE_LOAD);
    $page->click("[data-test=\"footer-policies\"] a[href=\"/eg/en/policies/{$slugs['slugEn']}\"]");
    expect(browserSoon($page, "window.location.pathname === '/eg/en/policies/{$slugs['slugEn']}' && document.querySelector('[data-test=\"content-policy\"]') !== null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('opens a store\'s switched-off policy labelled as old, with its dates there and no current one to link, on a phone in both languages; the other store still has it in force', function () {
    $run = Cb::run();
    Cx::editor([ContentPermissions::POLICY_MANAGE], ['ae', 'eg']);
    $words = ['kind' => 'OTHER', 'storeIds' => [Fx::storeId('ae'), Fx::storeId('eg')], 'titleAr' => "سياسة التركيب {$run}", 'titleEn' => "Fitting terms {$run}", 'slugAr' => "التركيب-{$run}", 'slugEn' => "fitting-{$run}", 'bodyAr' => 'نركّب خلال أسبوع.', 'bodyEn' => 'We fit within a week.'];
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 08:00:00+00'));
    $id = app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(...[...$words, 'id' => null, 'version' => 0])));
    app(PublishPolicyHandler::class)->handle(new PublishPolicy($id, 1));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 08:00:00+00'));
    app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($id, 2, [Fx::storeId('eg')]));
    CarbonImmutable::setTestNow();
    app(ContentCache::class)->invalidate();

    $page = visit("/eg/en/policies/fitting-{$run}");
    $page->resize(375, 812);

    expect(browserSoon($page, "document.querySelector('[data-test=\"policy-old\"]')?.textContent === 'This is an old version of the Fitting terms {$run}. It is no longer valid.' && document.querySelector('[data-test=\"policy-in-force\"]')?.textContent === 'In force from November 2, 2026 to November 10, 2026.' && document.querySelector('[data-test=\"policy-current\"]') === null"))->toBeTrue()
        ->and($page->script("document.querySelector('meta[name=\"robots\"]')?.getAttribute('content') ?? ''"))->toContain('noindex')
        ->and($page->script("[...document.querySelectorAll('[data-test=\"footer-policies\"] a')].some((link) => link.textContent.includes('{$run}'))"))->toBeFalse()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->navigate('/eg/ar/policies/'.rawurlencode("التركيب-{$run}"), BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.lang === 'ar' && document.querySelector('[data-test=\"policy-in-force\"]')?.textContent.includes('2026')"))->toBeTrue()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    // The UAE still has it in force: its current page.
    $page->navigate("/ae/en/policies/fitting-{$run}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"policy-updated\"]') !== null && document.querySelector('[data-test=\"policy-past\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
