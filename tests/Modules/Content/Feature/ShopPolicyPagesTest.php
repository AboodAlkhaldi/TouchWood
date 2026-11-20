<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemaps;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemapsHandler;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicy;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicyHandler;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicy;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicyHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyInput;
use Modules\Content\Infrastructure\Files\LocalSitemapFiles;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| Build 4, step 4 (flags-2026-11.md S3.1, S3.2, S3.5; the owner's answers of 2026-11-20): a policy's page in the shop,
| "Last updated" under its title (F15), a kept version at `?version=N` with a line to the current one, the 404s (a number
| it never had, a draft, another store's), its old addresses forwarding, the sitemap, and the footer's row of policy links
| (F19) - each with its queries, warm. Every helper here is named after this file's subject: a Pest file's functions are
| global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11 09:00:00+00'));
    Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa', 'eg']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $with
 * @param  list<string>  $codes
 */
function shopPolicySave(array $with = [], array $codes = ['sa']): string
{
    return app(SavePolicyHandler::class)->handle(new SavePolicy(new PolicyInput(...[
        'id' => null,
        'kind' => 'TERMS',
        'storeIds' => array_map(static fn (string $code): string => Fx::storeId($code), $codes),
        'version' => 0,
        'titleAr' => 'الشروط والأحكام',
        'titleEn' => 'Terms and Conditions',
        'slugAr' => 'الشروط',
        'slugEn' => 'terms',
        'bodyAr' => "# الطلبات\n\nتسري على كل طلب.",
        'bodyEn' => "# Orders\n\nThey apply to every order.",
        ...$with,
    ])));
}

function shopPolicyVersion(string $id): int
{
    return (int) DB::table('content.policies')->where('id', $id)->value('version');
}

function shopPolicyPublish(string $id): void
{
    app(PublishPolicyHandler::class)->handle(new PublishPolicy($id, shopPolicyVersion($id)));
}

/**
 * A shop page's props, as Inertia hands them; the page a fresh request.
 *
 * @return array<string, mixed>
 */
function shopPolicyPage(string $url, ?string $component = 'Content/Storefront/Policy'): array
{
    app()->forgetScopedInstances();
    $props = [];
    get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props, $component): void {
        if ($component !== null) {
            $page->component($component);
        }

        $props = $page->toArray()['props'];
    });

    return $props;
}

function shopPolicyAnswer(string $url): string
{
    app()->forgetScopedInstances();
    $response = get($url);

    return $response->getStatusCode().' '.($response->headers->get('Location') ?? '');
}

/** How many queries a page asks, warm: once to fill the caches, then counted as a new request. */
function shopPolicyQueries(string $url): int
{
    app()->forgetScopedInstances();
    get($url)->assertOk();
    app()->forgetScopedInstances();
    DB::flushQueryLog();
    DB::enableQueryLog();
    get($url)->assertOk();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

describe('a policy\'s page (S3.1, S3.2)', function () {
    it('draws the published policy with "Last updated" from when its text was published, in each language', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 10:00:00+00'));
        $policy = shopPolicySave();
        shopPolicyPublish($policy);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11 09:00:00+00'));

        $en = shopPolicyPage('/sa/en/policies/terms');
        $ar = shopPolicyPage('/sa/ar/policies/'.rawurlencode('الشروط'));

        expect($en['title'])->toBe('Terms and Conditions')
            ->and($en['blocks'][0])->toMatchArray(['type' => 'heading'])
            ->and($en['version'])->toBe(1)
            ->and($en['updatedAt'])->toBe('2026-11-05T10:00:00+00:00')
            ->and($en['past'])->toBeFalse()
            ->and($en['current'])->toBeNull()
            ->and($en['address']['canonical'])->toBe(url('/sa/en/policies/terms'))
            ->and($en['address']['alternate'])->toBe(url('/sa/ar/policies/'.rawurlencode('الشروط')))
            ->and($en['address']['noindex'])->toBeFalse()
            ->and($ar['title'])->toBe('الشروط والأحكام')
            ->and($ar['updatedAt'])->toBe('2026-11-05T10:00:00+00:00');
    });

    it('shows a kept version at ?version=N with the line to the current one, kept out of search engines', function () {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 08:00:00+00'));
        $policy = shopPolicySave();
        shopPolicyPublish($policy);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-09 12:00:00+00'));
        // A published policy's changed words are its next version at once.
        shopPolicySave(['id' => $policy, 'version' => shopPolicyVersion($policy), 'titleEn' => 'Terms of Sale', 'bodyEn' => 'New terms.']);

        $past = shopPolicyPage('/sa/en/policies/terms?version=1');
        $current = shopPolicyPage('/sa/en/policies/terms');
        $same = shopPolicyPage('/sa/en/policies/terms?version=2');

        expect($past['title'])->toBe('Terms and Conditions')
            ->and($past['blocks'][0])->toMatchArray(['type' => 'heading'])
            ->and($past['version'])->toBe(1)
            ->and($past['updatedAt'])->toBe('2026-11-01T08:00:00+00:00')
            ->and($past['past'])->toBeTrue()
            ->and($past['current'])->toBe(url('/sa/en/policies/terms'))
            ->and($past['address']['noindex'])->toBeTrue()
            ->and($past['address']['canonical'])->toBe(url('/sa/en/policies/terms'))
            ->and($current['title'])->toBe('Terms of Sale')
            ->and($current['version'])->toBe(2)
            ->and($current['updatedAt'])->toBe('2026-11-09T12:00:00+00:00')
            // The current number is the current page.
            ->and($same['past'])->toBeFalse()
            ->and($same['title'])->toBe('Terms of Sale');
    });

    it('answers 404 for a number it never had or anything but one, a draft, an unpublished one and another store\'s', function () {
        $published = shopPolicySave();
        shopPolicyPublish($published);
        shopPolicySave(['kind' => 'PRIVACY', 'titleAr' => 'الخصوصية', 'titleEn' => 'Privacy', 'slugAr' => 'الخصوصية', 'slugEn' => 'privacy']);
        $gone = shopPolicySave(['kind' => 'RETURNS', 'titleAr' => 'الاسترجاع', 'titleEn' => 'Returns', 'slugAr' => 'الاسترجاع', 'slugEn' => 'returns']);
        shopPolicyPublish($gone);
        app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($gone, shopPolicyVersion($gone)));

        expect(shopPolicyAnswer('/sa/en/policies/terms?version=2'))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=0'))->toBe('404 ')
            // A number written another way is not its address: one page per version.
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=01'))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=one'))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version[]=1'))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/privacy'))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/returns'))->toBe('404 ')
            ->and(shopPolicyAnswer('/eg/en/policies/terms'))->toBe('404 ')
            ->and(shopPolicyAnswer('/eg/en/policies/terms?version=1'))->toBe('404 ');
    });

    it('forwards an old address (301) as a page\'s does, the version asked kept, and the other language\'s', function () {
        $policy = shopPolicySave();
        shopPolicyPublish($policy);
        shopPolicySave(['id' => $policy, 'version' => shopPolicyVersion($policy), 'slugEn' => 'terms-of-sale']);

        expect(shopPolicyAnswer('/sa/en/policies/terms'))->toBe('301 '.url('/sa/en/policies/terms-of-sale'))
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=1'))->toBe('301 '.url('/sa/en/policies/terms-of-sale?version=1'))
            ->and(shopPolicyAnswer('/sa/ar/policies/terms-of-sale'))->toBe('301 '.url('/sa/ar/policies/'.rawurlencode('الشروط')))
            // Another store's address is never forwarded to.
            ->and(shopPolicyAnswer('/eg/en/policies/terms'))->toBe('404 ');
    });

    it('lists the current address of each policy a store publishes in its sitemap, never a draft or another store\'s', function () {
        config(['app.url' => 'https://touchwood.test']);
        Storage::fake(LocalSitemapFiles::DISK);
        shopPolicyPublish(shopPolicySave([], ['sa', 'eg']));
        shopPolicySave(['kind' => 'PRIVACY', 'titleAr' => 'الخصوصية', 'titleEn' => 'Privacy', 'slugAr' => 'الخصوصية', 'slugEn' => 'privacy']);
        Fx::asSystem(fn () => app(BuildSitemapsHandler::class)->handle(new BuildSitemaps));

        $sa = (string) get('/sa/sitemap.xml')->assertOk()->getContent();
        $eg = (string) get('/eg/sitemap.xml')->assertOk()->getContent();
        $ae = (string) get('/ae/sitemap.xml')->assertOk()->getContent();

        expect($sa)->toContain('<loc>https://touchwood.test/sa/en/policies/terms</loc>', '<loc>https://touchwood.test/sa/ar/policies/'.rawurlencode('الشروط').'</loc>')
            ->and(str_contains($sa, 'policies/privacy'))->toBeFalse()
            ->and($eg)->toContain('<loc>https://touchwood.test/eg/en/policies/terms</loc>')
            ->and($ae)->not->toContain('/policies/');
    });
});

describe('the footer\'s row of policy links (S3.5, F19)', function () {
    it('names the store\'s published policies in the kinds\' order, in the page\'s language; none when it has none', function () {
        expect(shopPolicyPage('/sa/en', null)['frame']['content']['policies'])->toBe([]);

        shopPolicyPublish(shopPolicySave(['kind' => 'RETURNS', 'titleAr' => 'الاسترجاع', 'titleEn' => 'Returns', 'slugAr' => 'الاسترجاع', 'slugEn' => 'returns']));
        shopPolicyPublish(shopPolicySave([], ['sa', 'eg']));
        shopPolicySave(['kind' => 'PRIVACY', 'titleAr' => 'الخصوصية', 'titleEn' => 'Privacy', 'slugAr' => 'الخصوصية', 'slugEn' => 'privacy']);

        expect(shopPolicyPage('/sa/en', null)['frame']['content']['policies'])->toBe([['title' => 'Terms and Conditions', 'slug' => 'terms'], ['title' => 'Returns', 'slug' => 'returns']])
            ->and(shopPolicyPage('/sa/ar', null)['frame']['content']['policies'])->toBe([['title' => 'الشروط والأحكام', 'slug' => 'الشروط'], ['title' => 'الاسترجاع', 'slug' => 'الاسترجاع']])
            ->and(shopPolicyPage('/eg/en', null)['frame']['content']['policies'])->toBe([['title' => 'Terms and Conditions', 'slug' => 'terms']])
            ->and(shopPolicyPage('/ae/en', null)['frame']['content']['policies'])->toBe([]);
    });
});

describe('the queries (owner, 2026-10-19: 8 a shop page is a target, not a cap)', function () {
    it('records a policy\'s page and a kept version\'s, warm, for a guest', function () {
        $policy = shopPolicySave();
        shopPolicyPublish($policy);
        shopPolicySave(['id' => $policy, 'version' => shopPolicyVersion($policy), 'bodyEn' => 'New terms.']);

        // Measured 2026-11-20, warm, as ShopPagesTest measures the others: the frame's 6 (the store 2, Catalog's menu 2,
        // Content's frame 2 - its copy holds the footer's policy links, so they cost nothing more) and the page's own 2: the
        // policy with its current version's moment, then its body's files; a version asked 2 as well - the policy, then that
        // version's text and files in one. Files' addresses and sizes add 2 when a body holds an image. At the target of 8.
        expect(['page' => shopPolicyQueries('/sa/en/policies/terms'), 'past' => shopPolicyQueries('/sa/en/policies/terms?version=1')])->toBe(['page' => 8, 'past' => 8]);
    });
});
