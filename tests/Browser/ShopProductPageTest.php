<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetProductVideos\SetProductVideos;
use Modules\Catalog\Application\Command\SetProductVideos\SetProductVideosHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| The product page to the design, in a real browser (shop-design.md §6): the 460 px details column, the 30 px name, the
| price box with the price at 36 px - on a sale in red with «Save» - the choices as chips, the stepper whose total follows
| it, Add to cart's toast with View Cart, the tabs; on a phone the buy row fixed at the bottom with no tab bar, and the
| heart over the photo.
|
| Deliberately no RefreshDatabase (the browser suite's way): each run makes its own category and product.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Px::numberFrom((int) substr((string) hrtime(true), -8));
});

/**
 * A two-size product in Saudi Arabia at 30.00, its first size on sale at 24.00.
 */
function shopProductSlug(): string
{
    $sa = Fx::storeId('sa');
    $p = Px::ready(['60 cm', '80 cm'], Px::category('Kitchens'));
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore($sa, $p['product'], true)));
    Px::onSale('sa', $p['product']);
    Rx::set('sa', array_fill_keys($p['variants'], '30.00'));
    // On a whole second, a sale starting now starts at once (Pricing's SaleInput).
    CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
    Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale($sa, null, [$p['variants'][0]], null, '24.00', null, null)));
    CarbonImmutable::setTestNow();

    return (string) DB::table('catalog.product_slugs')->where('product_id', $p['product'])->where('locale', 'en')->where('is_current', true)->value('slug');
}

it('lays the product page out to the design, its total following the stepper and Add to cart saying so', function () {
    $slug = shopProductSlug();
    $page = visit('/sa/en/products/'.$slug);
    $page->resize(1440, 1000);
    $page->navigate('/sa/en/products/'.$slug, BROWSER_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"price-box\"]') !== null"))->toBeTrue()
        ->and($page->script("Math.round(document.querySelector('[data-test=\"price-box\"]').parentElement.getBoundingClientRect().width)"))->toBe(460)
        ->and($page->script("getComputedStyle(document.querySelector('h1')).fontSize"))->toBe('30px')
        // The size on sale: 24.00 at 36 px in our red, the old price readable, what a piece saves.
        ->and($page->script("getComputedStyle(document.querySelector('[data-test=\"price\"] bdi')).fontSize"))->toBe('36px')
        ->and($page->script("getComputedStyle(document.querySelector('[data-test=\"price\"] bdi')).color"))->toBe('rgb(152, 43, 36)')
        ->and($page->script("document.querySelector('[data-test=\"price-saving\"]').textContent"))->toContain('6.00')
        ->and($page->script("document.querySelectorAll('[data-test=\"choice-value\"]').length"))->toBe(2)
        ->and($page->script("[...document.querySelectorAll('[data-test^=\"tab-\"]')].map((tab) => tab.getAttribute('data-test'))"))->toContain('tab-returns');

    // + twice: 3 pieces, 72.00 before VAT.
    $page->script("document.querySelector('[data-test=\"add-more\"]').click()");
    $page->script("document.querySelector('[data-test=\"add-more\"]').click()");
    expect(browserSoon($page, "document.querySelector('#add-quantity').value === '3'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"add-total\"]')?.textContent.includes('72.00') === true"))->toBeTrue('the total did not follow the stepper');

    $page->script("document.querySelector('[data-test=\"add\"]').click()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"added\"]') !== null && document.querySelector('[data-test=\"view-cart\"]') !== null"))->toBeTrue('no toast said it was added')
        ->and(browserSoon($page, "document.querySelector('[data-test=\"header-cart-count\"]')?.textContent.trim() === '1'"))->toBeTrue('the header did not count the one product');

    // A phone: the buy row fixed at the bottom, no tab bar, the heart over the photo and not in the row.
    $page->resize(375, 812);
    $page->navigate('/sa/ar/products/'.$slug, BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"buy-row\"]') !== null"))->toBeTrue()
        ->and($page->script("Math.round(window.innerHeight - document.querySelector('[data-test=\"buy-row\"]').getBoundingClientRect().bottom)"))->toBe(0)
        ->and($page->script("getComputedStyle(document.querySelector('[data-test=\"buy-row\"]')).position"))->toBe('fixed')
        ->and($page->script("document.querySelector('[data-test=\"tab-bar\"]') === null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"gallery-heart\"]').offsetParent !== null && document.querySelector('[data-test=\"save-heart\"]').offsetParent === null"))->toBeTrue()
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('the page scrolls sideways');
    $page->assertNoJavaScriptErrors();
});

it('shows the product\'s videos after its photos, YouTube\'s thumbnails only until Play, the player in their place once pressed, in both languages and on a phone (S4.4)', function () {
    $slug = shopProductSlug();
    $product = (string) DB::table('catalog.product_slugs')->where('slug', $slug)->value('product_id');
    Fx::asSystem(fn () => app(SetProductVideosHandler::class)->handle(new SetProductVideos($product, [
        ['link' => 'https://youtu.be/dQw4w9WgXcQ', 'title_ar' => 'طريقة التركيب', 'title_en' => 'How to fit it'],
        ['link' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'],
    ])));
    $player = "performance.getEntriesByType('resource').map((entry) => entry.name).filter((name) => /youtube(-nocookie)?\\.com/.test(new URL(name).hostname)).length";

    foreach (['en' => ['Play Video', 'How to fit it', 'Video 1 of 2'], 'ar' => ['تشغيل الفيديو', 'طريقة التركيب', 'الفيديو 1 من 2']] as $locale => [$play, $title, $thumbLabel]) {
        $page = visit("/sa/{$locale}/products/{$slug}");
        $page->resize(1440, 1000);
        $page->navigate("/sa/{$locale}/products/".(string) DB::table('catalog.product_slugs')->where('product_id', $product)->where('locale', $locale)->where('is_current', true)->value('slug'), BROWSER_PAGE_LOAD);

        // After the photo's thumbnail, the videos' own: YouTube's images, nothing of its player.
        expect(browserSoon($page, "document.querySelectorAll('[data-test=\"gallery-video-thumb\"] img').length === 2"))->toBeTrue("{$locale}: the videos' thumbnails are not there")
            ->and($page->script("[...document.querySelectorAll('[data-test=\"gallery-video-thumb\"]')].map((thumb) => thumb.getAttribute('aria-label'))[0]"))->toBe($thumbLabel)
            ->and($page->script("document.querySelector('[data-test=\"gallery-video-thumb\"] img').getAttribute('src')"))->toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg')
            ->and($page->script('document.querySelectorAll("iframe").length'))->toBe(0)
            ->and($page->script($player))->toBe(0);

        // Chosen, the video takes the large place as its tile; pressed, its player opens there.
        $page->script("document.querySelector('[data-test=\"gallery-video-thumb\"]').click()");
        expect(browserSoon($page, "document.querySelector('[data-test=\"gallery-video\"]')?.getAttribute('aria-label') === '{$play}: {$title}'"))->toBeTrue("{$locale}: the large tile is not the video's")
            ->and($page->script("document.querySelector('[data-test=\"gallery-video\"] [data-test=\"video-title\"]').textContent"))->toBe($title)
            ->and($page->script('document.querySelectorAll("iframe").length'))->toBe(0);
        $page->click('[data-test="gallery-video"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"video-player\"]')?.getAttribute('src') === 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0'"))->toBeTrue("{$locale}: Play did not open the player")
            ->and($page->script("document.querySelector('[data-test=\"video-player\"]').getAttribute('referrerpolicy')"))->toBe('strict-origin-when-cross-origin')
            ->and($page->script('document.querySelectorAll("iframe").length'))->toBe(1);

        // A phone: the videos are slides after the photo, each its own tile; one pressed plays alone.
        $page->resize(375, 812);
        $page->navigate("/sa/{$locale}/products/".(string) DB::table('catalog.product_slugs')->where('product_id', $product)->where('locale', $locale)->where('is_current', true)->value('slug'), BROWSER_PAGE_LOAD);
        expect(browserSoon($page, "document.querySelectorAll('[data-test=\"gallery-swipe\"] [data-test=\"swipe-video\"]').length === 2"))->toBeTrue("{$locale}: the swipe has no videos")
            ->and($page->script("document.querySelector('[data-test=\"gallery-count\"]').textContent.trim()"))->toBe('1 / 3')
            ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue("{$locale}: the page scrolls sideways");
        // Swiped to the first video and pressed, it plays; swiped on to the next, its player goes (the review of 5b).
        $swipe = static fn (int $slide): string => "(() => { const box = document.querySelector('[data-test=\"gallery-swipe\"]'); box.scrollTo({ left: box.clientWidth * {$slide} * (document.documentElement.dir === 'rtl' ? -1 : 1), behavior: 'instant' }); })()";
        $page->script($swipe(1));
        expect(browserSoon($page, "document.querySelector('[data-test=\"gallery-count\"]').textContent.trim() === '2 / 3'"))->toBeTrue("{$locale}: the swipe did not reach the first video");
        $page->script("document.querySelector('[data-test=\"swipe-video\"]').click()");
        expect(browserSoon($page, "document.querySelectorAll('iframe').length === 1 && document.querySelector('[data-test=\"gallery-swipe\"] iframe') !== null"))->toBeTrue("{$locale}: the slide did not play");
        $page->script($swipe(2));
        expect(browserSoon($page, "document.querySelector('[data-test=\"gallery-count\"]').textContent.trim() === '3 / 3' && document.querySelectorAll('iframe').length === 0"))->toBeTrue("{$locale}: the video swiped away kept playing")
            // Every slide is the swipe's width, a photo's too: the count reads the slide in view.
            ->and($page->script("[...document.querySelector('[data-test=\"gallery-swipe\"]').children].every((slide) => Math.round(slide.getBoundingClientRect().width) === document.querySelector('[data-test=\"gallery-swipe\"]').clientWidth)"))->toBeTrue();
        $page->assertNoJavaScriptErrors();
    }
});
