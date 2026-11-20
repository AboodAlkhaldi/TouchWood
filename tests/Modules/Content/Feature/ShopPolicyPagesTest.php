<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            // Old and no longer valid (owner, 2026-11-20): when it was in force in this store, its kind for the link's words.
            ->and($past['inForce'])->toBe([['from' => '2026-11-01T08:00:00+00:00', 'until' => '2026-11-09T12:00:00+00:00']])
            ->and($past['kind'])->toBe('TERMS')
            ->and($current['inForce'])->toBe([])
            ->and($current['title'])->toBe('Terms of Sale')
            ->and($current['version'])->toBe(2)
            ->and($current['updatedAt'])->toBe('2026-11-09T12:00:00+00:00')
            // The current number is the current page.
            ->and($same['past'])->toBeFalse()
            ->and($same['title'])->toBe('Terms of Sale');
    });

    it('answers 404 for a number it never had or anything but one, a draft never published and another store\'s', function () {
        $published = shopPolicySave();
        shopPolicyPublish($published);
        shopPolicySave(['kind' => 'PRIVACY', 'titleAr' => 'الخصوصية', 'titleEn' => 'Privacy', 'slugAr' => 'الخصوصية', 'slugEn' => 'privacy']);

        expect(shopPolicyAnswer('/sa/en/policies/terms?version=2'))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=0'))->toBe('404 ')
            // A number written another way is not its address: one page per version.
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=01'))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=one'))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version[]=1'))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/privacy'))->toBe('404 ')
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

/**
 * @param  list<string>|null  $codes
 */
function shopPolicyOff(string $id, ?array $codes = null): ?string
{
    return app(UnpublishPolicyHandler::class)->handle(new UnpublishPolicy($id, shopPolicyVersion($id), $codes === null ? null : array_map(static fn (string $code): string => Fx::storeId($code), $codes)));
}

function shopPolicyAt(string $moment): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($moment));
}

describe('a text no longer valid (the owner\'s decisions of 2026-11-20)', function () {
    it('opens a bare version number the current policy never had from the policy that held the address before', function () {
        // Build 4's review, finding 1: a link with the number alone, made before links named their policy.
        shopPolicyAt('2026-11-01 08:00:00+00');
        $old = shopPolicySave();
        shopPolicyPublish($old);
        shopPolicyAt('2026-11-02 08:00:00+00');
        shopPolicySave(['id' => $old, 'version' => shopPolicyVersion($old), 'bodyEn' => "# Orders\n\nThey apply to every order placed."]);
        shopPolicyAt('2026-11-03 08:00:00+00');
        shopPolicyOff($old);
        $new = shopPolicySave(['titleEn' => 'Terms of Sale', 'bodyEn' => 'New terms.']);
        shopPolicyPublish($new);

        $two = shopPolicyPage('/sa/en/policies/terms?version=2');

        expect([$two['title'], $two['past'], $two['version']])->toBe(['Terms and Conditions', true, 2])
            ->and($two['inForce'])->toBe([['from' => '2026-11-02T08:00:00+00:00', 'until' => '2026-11-03T08:00:00+00:00']])
            ->and($two['current'])->toBe(url('/sa/en/policies/terms'))
            // The current policy's own version 1 stays its own; a number neither had is still a 404.
            ->and(shopPolicyPage('/sa/en/policies/terms?version=1')['past'])->toBeFalse()
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=3'))->toBe('404 ')
            // Named, the current policy's own number is not looked for elsewhere.
            ->and(shopPolicyAnswer("/sa/en/policies/terms?version=2&policy={$new}"))->toBe('404 ');
    });

    it('opens a store\'s switched-off policy labelled as old, with its dates there, while another store still has it in force', function () {
        shopPolicyAt('2026-11-01 08:00:00+00');
        $shared = shopPolicySave([], ['sa', 'eg']);
        shopPolicyPublish($shared);
        shopPolicyAt('2026-11-05 10:00:00+00');
        // Ahmed holds KSA alone, and turns it off.
        Cx::editor([ContentPermissions::POLICY_MANAGE], ['sa']);
        $copy = (string) shopPolicyOff($shared, ['sa']);
        shopPolicyAt('2026-11-06 09:00:00+00');

        $old = shopPolicyPage('/sa/en/policies/terms');
        $arabic = shopPolicyPage('/sa/ar/policies/'.rawurlencode('الشروط'));
        $egypt = shopPolicyPage('/eg/en/policies/terms');

        expect($old['title'])->toBe('Terms and Conditions')
            ->and($old['blocks'][0])->toMatchArray(['type' => 'heading'])
            ->and($old['past'])->toBeTrue()
            ->and($old['version'])->toBe(1)
            ->and($old['inForce'])->toBe([['from' => '2026-11-01T08:00:00+00:00', 'until' => '2026-11-05T10:00:00+00:00']])
            // KSA has no Terms now: nothing to link.
            ->and($old['current'])->toBeNull()
            ->and($old['address']['noindex'])->toBeTrue()
            ->and($old['address']['canonical'])->toBe(url("/sa/en/policies/terms?version=1&policy={$shared}"))
            ->and($old['address']['alternate'])->toBe(url('/sa/ar/policies/'.rawurlencode('الشروط')."?version=1&policy={$shared}"))
            ->and($arabic['title'])->toBe('الشروط والأحكام')
            ->and($arabic['past'])->toBeTrue()
            ->and($egypt['past'])->toBeFalse()
            ->and($egypt['inForce'])->toBe([])
            // Kept out of the footer.
            ->and(shopPolicyPage('/sa/en', null)['frame']['content']['policies'])->toBe([])
            ->and(shopPolicyPage('/eg/en', null)['frame']['content']['policies'])->toBe([['title' => 'Terms and Conditions', 'slug' => 'terms']]);

        // KSA's own copy published: the address is its current page; the old text stays at its own version link, which links
        // the current one.
        shopPolicyAt('2026-11-07 09:00:00+00');
        shopPolicyPublish($copy);
        $now = shopPolicyPage('/sa/en/policies/terms');
        $kept = shopPolicyPage("/sa/en/policies/terms?version=1&policy={$shared}");

        expect([$now['past'], $now['version'], $now['updatedAt']])->toBe([false, 1, '2026-11-07T09:00:00+00:00'])
            ->and($kept['past'])->toBeTrue()
            ->and($kept['inForce'])->toBe([['from' => '2026-11-01T08:00:00+00:00', 'until' => '2026-11-05T10:00:00+00:00']])
            ->and($kept['current'])->toBe(url('/sa/en/policies/terms'))
            ->and($kept['address']['canonical'])->toBe(url('/sa/en/policies/terms'))
            // A number alone is the current policy's: its own version 1, current.
            ->and(shopPolicyPage('/sa/en/policies/terms?version=1')['past'])->toBeFalse();
    });

    it('opens a policy unpublished everywhere as old, and links the store\'s current one of its kind once there is one', function () {
        shopPolicyAt('2026-11-01 08:00:00+00');
        $gone = shopPolicySave(['kind' => 'RETURNS', 'titleAr' => 'الاسترجاع', 'titleEn' => 'Returns', 'slugAr' => 'الاسترجاع', 'slugEn' => 'returns']);
        shopPolicyPublish($gone);
        // Terms published there: another kind, never the Returns' current one.
        shopPolicyPublish(shopPolicySave());
        shopPolicyAt('2026-11-03 08:00:00+00');
        shopPolicyOff($gone);

        $before = shopPolicyPage('/sa/en/policies/returns');

        $refunds = shopPolicySave(['kind' => 'RETURNS', 'titleAr' => 'الاسترداد', 'titleEn' => 'Refunds', 'slugAr' => 'الاسترداد', 'slugEn' => 'refunds']);
        shopPolicyPublish($refunds);
        $after = shopPolicyPage('/sa/en/policies/returns');

        expect([$before['past'], $before['current']])->toBe([true, null])
            ->and($before['inForce'])->toBe([['from' => '2026-11-01T08:00:00+00:00', 'until' => '2026-11-03T08:00:00+00:00']])
            ->and($after['past'])->toBeTrue()
            ->and($after['current'])->toBe(url('/sa/en/policies/refunds'))
            ->and($after['kind'])->toBe('RETURNS');
    });

    it('keeps an old text at its address, and at its version link, once a new policy takes the address', function () {
        shopPolicyAt('2026-11-01 08:00:00+00');
        $old = shopPolicySave();
        shopPolicyPublish($old);
        shopPolicyAt('2026-11-02 08:00:00+00');
        shopPolicyOff($old);
        $new = shopPolicySave(['titleEn' => 'Terms of Sale', 'bodyEn' => 'New terms.']);

        // A draft yet: the address still shows the old text.
        expect(shopPolicyPage('/sa/en/policies/terms')['title'])->toBe('Terms and Conditions');

        shopPolicyAt('2026-11-04 08:00:00+00');
        shopPolicyPublish($new);
        $current = shopPolicyPage('/sa/en/policies/terms');
        $kept = shopPolicyPage("/sa/en/policies/terms?version=1&policy={$old}");

        expect([$current['title'], $current['past']])->toBe(['Terms of Sale', false])
            ->and([$kept['title'], $kept['past'], $kept['current']])->toBe(['Terms and Conditions', true, url('/sa/en/policies/terms')])
            ->and($kept['inForce'])->toBe([['from' => '2026-11-01T08:00:00+00:00', 'until' => '2026-11-02T08:00:00+00:00']]);

        // The new one switched off too: the address shows the text last in force there - the new one's.
        shopPolicyAt('2026-11-05 08:00:00+00');
        shopPolicyOff($new);
        $last = shopPolicyPage('/sa/en/policies/terms');

        expect([$last['title'], $last['past']])->toBe(['Terms of Sale', true])
            ->and($last['inForce'])->toBe([['from' => '2026-11-04T08:00:00+00:00', 'until' => '2026-11-05T08:00:00+00:00']]);
    });

    it('shows the version asked at an address no policy is current at, and joins periods one after another into one', function () {
        shopPolicyAt('2026-11-01 08:00:00+00');
        $policy = shopPolicySave();
        shopPolicyPublish($policy);
        shopPolicyAt('2026-11-02 08:00:00+00');
        // Version 1 read at a new address while in force, then version 2 there.
        shopPolicySave(['id' => $policy, 'version' => shopPolicyVersion($policy), 'slugEn' => 'conditions']);
        shopPolicyAt('2026-11-03 08:00:00+00');
        shopPolicySave(['id' => $policy, 'version' => shopPolicyVersion($policy), 'slugEn' => 'conditions', 'titleEn' => 'Conditions', 'bodyEn' => 'New terms.']);
        shopPolicyAt('2026-11-04 08:00:00+00');
        shopPolicyOff($policy);

        $one = shopPolicyPage('/sa/en/policies/conditions?version=1');
        $last = shopPolicyPage('/sa/en/policies/conditions');

        expect([$one['title'], $one['version']])->toBe(['Terms and Conditions', 1])
            ->and($one['inForce'])->toBe([['from' => '2026-11-01T08:00:00+00:00', 'until' => '2026-11-03T08:00:00+00:00']])
            ->and([$last['title'], $last['version']])->toBe(['Conditions', 2]);
    });

    it('answers 404 for a version of the current policy never in force in this store', function () {
        shopPolicyAt('2026-11-01 08:00:00+00');
        $policy = shopPolicySave();
        shopPolicyPublish($policy);
        shopPolicyAt('2026-11-02 08:00:00+00');
        // Egypt joins with version 2.
        shopPolicySave(['id' => $policy, 'version' => shopPolicyVersion($policy), 'bodyEn' => 'New terms.'], ['sa', 'eg']);

        expect(shopPolicyAnswer('/sa/en/policies/terms?version=1'))->toStartWith('200')
            ->and(shopPolicyAnswer('/eg/en/policies/terms?version=1'))->toBe('404 ')
            ->and(shopPolicyAnswer("/eg/en/policies/terms?version=1&policy={$policy}"))->toBe('404 ');
    });

    it('links an Other\'s old text to the same policy only, never to another Other the store publishes', function () {
        $other = ['kind' => 'OTHER', 'titleAr' => 'التركيب', 'titleEn' => 'Fitting', 'slugAr' => 'التركيب', 'slugEn' => 'fitting'];
        shopPolicyAt('2026-11-01 08:00:00+00');
        $fitting = shopPolicySave($other);
        shopPolicyPublish($fitting);
        shopPolicyPublish(shopPolicySave(['kind' => 'OTHER', 'titleAr' => 'التوصيل', 'titleEn' => 'Delivery', 'slugAr' => 'التوصيل', 'slugEn' => 'delivery']));
        shopPolicyAt('2026-11-02 08:00:00+00');
        shopPolicySave(['id' => $fitting, 'version' => shopPolicyVersion($fitting), ...$other, 'bodyEn' => 'New fitting terms.']);

        expect(shopPolicyPage('/sa/en/policies/fitting?version=1')['current'])->toBe(url('/sa/en/policies/fitting'));

        shopPolicyOff($fitting);

        expect(shopPolicyPage('/sa/en/policies/fitting')['current'])->toBeNull();
    });

    it('answers 404 for a policy named another way than an id, one never in force in the store, a version it never had there, and a draft never published', function () {
        $policy = shopPolicySave();
        shopPolicyPublish($policy);
        shopPolicyAt('2026-11-12 09:00:00+00');
        shopPolicySave(['id' => $policy, 'version' => shopPolicyVersion($policy), 'bodyEn' => 'New terms.']);
        $draft = shopPolicySave(['kind' => 'PRIVACY', 'titleAr' => 'الخصوصية', 'titleEn' => 'Privacy', 'slugAr' => 'الخصوصية', 'slugEn' => 'privacy']);

        expect(shopPolicyAnswer("/sa/en/policies/terms?version=1&policy={$policy}"))->toStartWith('200')
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=1&policy=not-an-id'))->toBe('404 ')
            ->and(shopPolicyAnswer("/sa/en/policies/terms?version=1&policy[]={$policy}"))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/terms?version=1&policy='.strtolower((string) Str::ulid())))->toBe('404 ')
            ->and(shopPolicyAnswer("/sa/en/policies/terms?version=3&policy={$policy}"))->toBe('404 ')
            ->and(shopPolicyAnswer("/eg/en/policies/terms?version=1&policy={$policy}"))->toBe('404 ')
            ->and(shopPolicyAnswer("/sa/en/policies/privacy?policy={$draft}"))->toBe('404 ')
            ->and(shopPolicyAnswer('/sa/en/policies/privacy'))->toBe('404 ');
    });

    it('keeps a store\'s switched-off policy out of its sitemap', function () {
        config(['app.url' => 'https://touchwood.test']);
        Storage::fake(LocalSitemapFiles::DISK);
        $shared = shopPolicySave([], ['sa', 'eg']);
        shopPolicyPublish($shared);
        shopPolicyOff($shared, ['sa']);
        Fx::asSystem(fn () => app(BuildSitemapsHandler::class)->handle(new BuildSitemaps));

        expect((string) get('/sa/sitemap.xml')->assertOk()->getContent())->not->toContain('/policies/')
            ->and((string) get('/eg/sitemap.xml')->assertOk()->getContent())->toContain('<loc>https://touchwood.test/eg/en/policies/terms</loc>');
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
        // A kept version 9 since 2026-11-21 (the owner's decisions of 2026-11-20): one more, when that version was in force in
        // this store, for its dates and to refuse one never in force here (no choice: the notice names them).
        expect(['page' => shopPolicyQueries('/sa/en/policies/terms'), 'past' => shopPolicyQueries('/sa/en/policies/terms?version=1')])->toBe(['page' => 8, 'past' => 9]);
    });

    it('records a text no longer valid, warm, for a guest: by its version link, and at a switched-off store\'s address', function () {
        $shared = shopPolicySave([], ['sa', 'eg']);
        shopPolicyPublish($shared);
        shopPolicyOff($shared, ['sa']);

        // Measured 2026-11-21, warm: the frame's 6, the current policy asked at the address (none), then the old text's
        // periods in the store (one read for its version and dates) and its words and files: 9 by its version link, the
        // staff's order page's. A plain address first looks where its policy is now, as every Content address does (the
        // holder in each language, and the holder's current address): 12. Over the target of 8, no choice: the old text
        // is found only once nothing current answers there.
        expect(['link' => shopPolicyQueries("/sa/en/policies/terms?version=1&policy={$shared}"), 'address' => shopPolicyQueries('/sa/en/policies/terms')])->toBe(['link' => 9, 'address' => 12]);
    });
});
