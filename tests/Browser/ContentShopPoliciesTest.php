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

            expect(browserSoon($page, "document.querySelector('[data-test=\"content-policy\"]') !== null && document.querySelector('[data-test=\"policy-updated\"]') !== null"))->toBeTrue("{$address}: not drawn")
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
    expect(browserSoon($page, "document.querySelector('[data-test=\"policy-past\"]')?.textContent.startsWith('This is version 1 of this policy, published November 1, 2026.') && document.body.textContent.includes('Two years')"))->toBeTrue()
        ->and($page->script("document.querySelector('meta[name=\"robots\"]')?.getAttribute('content') ?? ''"))->toContain('noindex');
    $page->click('[data-test="policy-current"]');
    expect(browserSoon($page, "window.location.search === '' && document.body.textContent.includes('Three years')"))->toBeTrue();

    // The footer's link opens the policy.
    $page->navigate('/eg/en', BROWSER_PAGE_LOAD);
    $page->click("[data-test=\"footer-policies\"] a[href=\"/eg/en/policies/{$slugs['slugEn']}\"]");
    expect(browserSoon($page, "window.location.pathname === '/eg/en/policies/{$slugs['slugEn']}' && document.querySelector('[data-test=\"content-policy\"]') !== null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
