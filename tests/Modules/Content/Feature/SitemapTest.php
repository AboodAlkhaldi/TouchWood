<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemaps;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemapsHandler;
use Modules\Content\Application\Command\PublishPage\PublishPage;
use Modules\Content\Application\Command\PublishPage\PublishPageHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Sitemap\SitemapBuilder;
use Modules\Content\Infrastructure\Files\LocalSitemapFiles;
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
