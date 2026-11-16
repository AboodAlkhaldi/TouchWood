<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
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
        ->and(browserSoon($page, "document.querySelector('[data-test=\"header-cart-pieces\"]')?.textContent.trim() === '3'"))->toBeTrue();

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
