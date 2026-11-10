<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemaps;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemapsHandler;
use Modules\Content\Application\Command\PublishPage\PublishPage;
use Modules\Content\Application\Command\PublishPage\PublishPageHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Sitemap\SitemapBuilder;
use Modules\Content\Infrastructure\Files\LocalSitemapFiles;
use Modules\Content\Infrastructure\Queue\BuildSitemapsJob;
use Modules\Content\Infrastructure\Queue\OneSitemapBuild;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Public\Contracts\PlatformApi;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\ContentRedirects as Rx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The sitemaps (content.md §1.14, §8 #15): /sitemap.xml an index of each on store's /{store}/sitemap.xml, each listing
| home, categories, brands, listed products, pages, posts and projects in both languages with alternates and the last
| change, split at 50,000. Built by the job into files, never in a shopper's request; an off store, drafts and
| not-available pages left out.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['app.url' => 'https://touchwood.test']);
    Storage::fake(LocalSitemapFiles::DISK);
});

/** A ready product chosen and on sale in a store. */
function sitemapProduct(string $store = 'sa'): string
{
    $p = Px::ready(['60 cm'], Px::category('Kitchens'));
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $p['product'], true)));
    Px::onSale($store, $p['product']);

    return $p['product'];
}

function sitemapBuild(): void
{
    Fx::asSystem(fn () => app(BuildSitemapsHandler::class)->handle(new BuildSitemaps));
}

/**
 * A published page of a store.
 *
 * @return array<string, string> its slug in each language
 */
function sitemapPage(string $store = 'sa'): array
{
    Cx::editor([ContentPermissions::PAGE_MANAGE], [$store]);
    $id = Cx::page(code: $store);
    app(PublishPageHandler::class)->handle(new PublishPage($id, 1));

    return array_map(strval(...), DB::table('content.page_translations')->where('page_id', $id)->pluck('slug', 'locale')->all());
}

/** @return list<string> every <loc> of a file */
function sitemapLocs(string $xml): array
{
    preg_match_all('#<loc>([^<]+)</loc>#', $xml, $found);

    return array_map(static fn (string $loc): string => html_entity_decode($loc, ENT_XML1), $found[1]);
}

it('lists a store\'s home, Catalog\'s listed pages and Content\'s published ones, in both languages, each naming the other', function () {
    $product = sitemapProduct();
    $page = sitemapPage();
    // A draft page is never in it.
    Cx::page();
    $productAr = (string) DB::table('catalog.product_slugs')->where('product_id', $product)->where('locale', 'ar')->where('is_current', true)->value('slug');
    $productEn = (string) DB::table('catalog.product_slugs')->where('product_id', $product)->where('locale', 'en')->where('is_current', true)->value('slug');

    sitemapBuild();
    $xml = (string) get('/sa/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    $locs = sitemapLocs($xml);

    $productUrlAr = 'https://touchwood.test/sa/ar/products/'.rawurlencode($productAr);
    expect($locs)->toContain('https://touchwood.test/sa/ar', 'https://touchwood.test/sa/en', $productUrlAr, "https://touchwood.test/sa/en/products/{$productEn}")
        ->toContain('https://touchwood.test/sa/ar/pages/'.rawurlencode($page['ar']), "https://touchwood.test/sa/en/pages/{$page['en']}")
        ->and(count(array_filter($locs, static fn (string $loc): bool => str_contains($loc, '/pages/'))))->toBe(2)
        ->and(count(array_filter($locs, static fn (string $loc): bool => str_contains($loc, '/categories/'))))->toBe(2)
        ->and(count(array_filter($locs, static fn (string $loc): bool => str_contains($loc, '/brands/'))))->toBe(2);

    // The Arabic product's entry names both languages, with its last change.
    preg_match('#<url><loc>'.preg_quote(htmlspecialchars($productUrlAr, ENT_XML1), '#').'</loc>(.*?)</url>#', $xml, $entry);
    expect($entry[1] ?? '')->toContain('<lastmod>')
        ->toContain('hreflang="ar" href="'.htmlspecialchars($productUrlAr, ENT_XML1).'"')
        ->toContain('hreflang="en" href="https://touchwood.test/sa/en/products/'.$productEn.'"');
});

it('names every on store\'s home as the home\'s alternates, by language and country', function () {
    sitemapBuild();
    $xml = (string) get('/sa/sitemap.xml')->getContent();
    preg_match('#<url><loc>https://touchwood.test/sa/ar</loc>(.*?)</url>#', $xml, $home);

    foreach (app(PlatformApi::class)->stores() as $store) {
        expect($home[1] ?? '')->toContain('hreflang="ar-'.strtoupper($store->countryCode).'" href="https://touchwood.test/'.$store->code.'/ar"')
            ->toContain('hreflang="en-'.strtoupper($store->countryCode).'" href="https://touchwood.test/'.$store->code.'/en"');
    }
});

it('leaves out what a store does not list, and what another store lists', function () {
    $elsewhere = sitemapProduct('ae');
    $slug = (string) DB::table('catalog.product_slugs')->where('product_id', $elsewhere)->where('locale', 'en')->where('is_current', true)->value('slug');

    sitemapBuild();

    expect((string) get('/sa/sitemap.xml')->getContent())->not->toContain("/products/{$slug}")
        ->and((string) get('/ae/sitemap.xml')->getContent())->toContain("/ae/en/products/{$slug}");
});

it('indexes each on store\'s file, and an off store answers not found and leaves the index at once', function () {
    sitemapBuild();
    $index = (string) get('/sitemap.xml')->assertOk()->getContent();

    expect(sitemapLocs($index))->toBe(['https://touchwood.test/sa/sitemap.xml', 'https://touchwood.test/eg/sitemap.xml', 'https://touchwood.test/ae/sitemap.xml']);

    Rx::storeOn('eg', false);

    expect(sitemapLocs((string) get('/sitemap.xml')->getContent()))->toBe(['https://touchwood.test/sa/sitemap.xml', 'https://touchwood.test/ae/sitemap.xml']);
    get('/eg/sitemap.xml')->assertNotFound();

    // The next build removes its files too.
    sitemapBuild();
    expect(DB::table('content.sitemaps')->where('store_id', Fx::storeId('sa'))->count())->toBe(1)
        ->and(DB::table('content.sitemaps')->count())->toBe(2);
});

it('answers a store with no file, an unknown store and a part that is not there not found', function () {
    get('/sa/sitemap.xml')->assertNotFound();
    sitemapBuild();
    get('/zz/sitemap.xml')->assertNotFound();
    get('/sa/sitemap-2.xml')->assertNotFound();
    get('/sa/sitemap-1.xml')->assertNotFound();
});

it('replaces a store\'s files on each build, deleting the ones replaced', function () {
    sitemapBuild();
    $first = (string) DB::table('content.sitemaps')->where('store_id', Fx::storeId('sa'))->value('file_key');
    sitemapBuild();
    $second = (string) DB::table('content.sitemaps')->where('store_id', Fx::storeId('sa'))->value('file_key');

    expect($second)->not->toBe($first)
        ->and(Storage::disk(LocalSitemapFiles::DISK)->exists($first))->toBeFalse()
        ->and(Storage::disk(LocalSitemapFiles::DISK)->exists($second))->toBeTrue();
});

it('splits a store\'s addresses into files of at most the limit', function () {
    sitemapProduct();
    $store = app(PlatformApi::class)->storeByCode('sa') ?? throw new LogicException('No store.');

    $parts = app(SitemapBuilder::class)->build($store, app(PlatformApi::class)->stores(), 'https://touchwood.test', perFile: 3);

    // The home 2, a category 2, a brand 2, a product 2: 8 addresses, three files of 3, 3 and 2.
    expect(array_column($parts, 'urls'))->toBe([3, 3, 2])
        ->and(array_map(static fn (array $part): int => count(sitemapLocs($part['xml'])), $parts))->toBe([3, 3, 2])
        ->and(SitemapBuilder::fileAddress('https://touchwood.test', 'sa', 1))->toBe('https://touchwood.test/sa/sitemap.xml')
        ->and(SitemapBuilder::fileAddress('https://touchwood.test', 'sa', 2))->toBe('https://touchwood.test/sa/sitemap-2.xml');
});

it('serves the files with no session and no store entered', function () {
    sitemapBuild();
    $response = get('/sa/sitemap.xml');

    expect($response->headers->getCookies())->toBe([]);
});

describe('the PR 4 review', function () {
    it('writes a file an XML reader reads as the sitemap protocol has it', function () {
        sitemapProduct();
        sitemapBuild();

        $xml = simplexml_load_string((string) get('/sa/sitemap.xml')->getContent());
        expect($xml)->toBeInstanceOf(SimpleXMLElement::class);
        $xml = $xml ?: throw new LogicException('Not XML.');

        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');
        $urls = $xml->xpath('/s:urlset/s:url') ?: [];

        // The home 2, a category 2, a brand 2, a product 2.
        expect($urls)->toHaveCount(8);

        foreach ($urls as $url) {
            $url->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $url->registerXPathNamespace('x', 'http://www.w3.org/1999/xhtml');
            $loc = (string) ($url->xpath('s:loc')[0] ?? '');
            $links = array_map(static fn (SimpleXMLElement $link): string => (string) $link['href'], $url->xpath('x:link[@rel="alternate"]') ?: []);

            // Each address names itself among its alternates, each alternate an address of the site.
            expect($loc)->toStartWith('https://touchwood.test/')
                ->and($links)->toContain($loc);

            foreach ($links as $href) {
                expect($href)->toStartWith('https://touchwood.test/');
            }
        }

        $index = simplexml_load_string((string) get('/sitemap.xml')->getContent()) ?: throw new LogicException('Not XML.');
        $index->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        expect(array_map('strval', $index->xpath('/s:sitemapindex/s:sitemap/s:loc') ?: []))->toContain('https://touchwood.test/sa/sitemap.xml');
    });

    it('lists the blog\'s and the projects\' lists only once a post or a project is published', function () {
        $store = Fx::storeId('sa');
        $now = CarbonImmutable::now();
        sitemapBuild();
        $locs = sitemapLocs((string) get('/sa/sitemap.xml')->getContent());
        expect(array_values(array_filter($locs, static fn (string $loc): bool => str_contains($loc, '/blog') || str_contains($loc, '/projects'))))->toBe([]);

        $post = strtolower((string) Str::ulid());
        DB::table('content.blog_posts')->insert(['id' => $post, 'store_id' => $store, 'status' => 'PUBLISHED', 'published_at' => $now, 'cover_media_id' => Cx::image(), 'created_at' => $now, 'updated_at' => $now]);
        $project = strtolower((string) Str::ulid());
        DB::table('content.projects')->insert(['id' => $project, 'store_id' => $store, 'status' => 'PUBLISHED', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        foreach (['ar' => 'دليل', 'en' => 'guide'] as $locale => $slug) {
            DB::table('content.blog_post_translations')->insert(['post_id' => $post, 'locale' => $locale, 'store_id' => $store, 'title' => 'Guide', 'slug' => $slug, 'body' => '{"blocks": []}']);
            DB::table('content.project_translations')->insert(['project_id' => $project, 'locale' => $locale, 'store_id' => $store, 'title' => 'Villa', 'slug' => "villa-{$locale}", 'body' => '{"blocks": []}']);
        }

        sitemapBuild();
        $locs = sitemapLocs((string) get('/sa/sitemap.xml')->getContent());

        expect($locs)->toContain('https://touchwood.test/sa/ar/blog', 'https://touchwood.test/sa/en/blog', 'https://touchwood.test/sa/ar/projects', 'https://touchwood.test/sa/en/projects')
            ->toContain('https://touchwood.test/sa/en/blog/guide', 'https://touchwood.test/sa/ar/projects/villa-ar');
    });

    it('serves a store\'s second file, and names both in the index', function () {
        $store = Fx::storeId('sa');
        Storage::disk(LocalSitemapFiles::DISK)->put('content/sitemaps/one.xml', '<urlset>1</urlset>');
        Storage::disk(LocalSitemapFiles::DISK)->put('content/sitemaps/two.xml', '<urlset>2</urlset>');
        DB::table('content.sitemaps')->insert([
            ['store_id' => $store, 'part' => 1, 'file_key' => 'content/sitemaps/one.xml', 'urls' => 50000, 'built_at' => CarbonImmutable::now()],
            ['store_id' => $store, 'part' => 2, 'file_key' => 'content/sitemaps/two.xml', 'urls' => 3, 'built_at' => CarbonImmutable::now()],
        ]);

        expect(sitemapLocs((string) get('/sitemap.xml')->getContent()))->toBe(['https://touchwood.test/sa/sitemap.xml', 'https://touchwood.test/sa/sitemap-2.xml'])
            ->and((string) get('/sa/sitemap.xml')->getContent())->toBe('<urlset>1</urlset>')
            ->and((string) get('/sa/sitemap-2.xml')->getContent())->toBe('<urlset>2</urlset>');
        get('/sa/sitemap-3.xml')->assertNotFound();
    });

    it('builds one at a time: a second build while one holds the lock waits in the queue, building nothing', function () {
        expect((new BuildSitemapsJob)->middleware()[1] ?? null)->toBeInstanceOf(OneSitemapBuild::class);

        // Another worker's build, on a connection of its own, holds the lock.
        config(['database.connections.pgsql_other_worker' => config('database.connections.pgsql')]);
        $other = DB::connection('pgsql_other_worker');
        $other->select('SELECT pg_advisory_lock(hashtext(?))', [OneSitemapBuild::KEY]);

        try {
            BuildSitemapsJob::dispatch();
            expect(DB::table('content.sitemaps')->count())->toBe(0);
        } finally {
            $other->select('SELECT pg_advisory_unlock(hashtext(?))', [OneSitemapBuild::KEY]);
            DB::purge('pgsql_other_worker');
        }

        BuildSitemapsJob::dispatch();
        expect(DB::table('content.sitemaps')->count())->toBe(3);

        // The lock is let go when the build ends: the other worker takes it at once.
        $other = DB::connection('pgsql_other_worker');
        expect((bool) ($other->selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS got', [OneSitemapBuild::KEY])?->got))->toBeTrue();
        $other->select('SELECT pg_advisory_unlock(hashtext(?))', [OneSitemapBuild::KEY]);
        DB::purge('pgsql_other_worker');
    });

    it('names a home for its country once, when two on stores share a country', function () {
        // A store's country is not unique: the UAE store set to the base store's country.
        DB::table('platform.stores')->where('code', 'ae')->update(['country_code' => 'SA']);
        app(StoreDirectory::class)->invalidate();
        sitemapBuild();

        preg_match('#<url><loc>https://touchwood.test/sa/ar</loc>(.*?)</url>#', (string) get('/sa/sitemap.xml')->getContent(), $sa);
        preg_match('#<url><loc>https://touchwood.test/ae/ar</loc>(.*?)</url>#', (string) get('/ae/sitemap.xml')->getContent(), $ae);

        expect(substr_count($sa[1] ?? '', 'hreflang="ar-SA"'))->toBe(1)
            ->and($sa[1] ?? '')->toContain('hreflang="ar-SA" href="https://touchwood.test/sa/ar"')
            ->and(str_contains($sa[1] ?? '', '/ae/'))->toBeFalse()
            // The later store's home names only its own two languages.
            ->and($ae[1] ?? '')->toBe('<xhtml:link rel="alternate" hreflang="ar" href="https://touchwood.test/ae/ar"/><xhtml:link rel="alternate" hreflang="en" href="https://touchwood.test/ae/en"/>');
    });
});
